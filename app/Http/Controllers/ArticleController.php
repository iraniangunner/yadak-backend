<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    /**
     * لیست عمومی مقالات منتشرشده.
     */
    public function index(Request $request)
    {
        $articles = Article::query()
            ->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('published_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json($articles);
    }

    /**
     * نمایش یک مقاله (با اسلاگ) + محصولات پیشنهادی مرتبط زیرش (بند ۶ سند).
     * برای مهمان/مشتری فقط مقاله‌ی منتشرشده قابل مشاهده‌ست.
     */
    public function show(string $slug)
    {
        $article = Article::where('slug', $slug)->first();

        if (! $article || ! $article->isVisible()) {
            return response()->json(['message' => 'مقاله یافت نشد.'], 404);
        }

        $article->load('products', 'author:id,name');

        return response()->json(['article' => $article]);
    }

    /**
     * لیست کامل مقالات برای ادمین (شامل پیش‌نویس‌های منتشرنشده).
     */
    public function adminIndex(Request $request)
    {
        $articles = Article::query()
            ->when($request->filled('is_published'), fn($q) => $q->where('is_published', $request->boolean('is_published')))
            ->with('author:id,name')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($articles);
    }

    public function store(Request $request)
    {
        $validated = $this->validateArticle($request);

        $slug = Str::slug($validated['title']);
        if (Article::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $thumbnailPath = $request->hasFile('thumbnail')
            ? $this->imageService->store($request->file('thumbnail'), 'articles')
            : null;

        $article = Article::create([
            ...$validated,
            'slug' => $slug,
            'thumbnail' => $thumbnailPath,
            'author_id' => $request->user()->id,
            'published_at' => ($validated['is_published'] ?? false) ? ($validated['published_at'] ?? now()) : null,
        ]);

        if ($request->filled('product_ids')) {
            $this->syncProducts($article, $request->input('product_ids'));
        }

        return response()->json(['article' => $article->fresh('products')], 201);
    }

    public function update(Request $request, Article $article)
    {
        $validated = $this->validateArticle($request, $article);

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $this->imageService->replace(
                $article->thumbnail,
                $request->file('thumbnail'),
                'articles'
            );
        }

        // اگه الان publish می‌شه و قبلاً published_at نداشته، همین الان رو ثبت کن
        if (($validated['is_published'] ?? $article->is_published) && ! $article->published_at && ! isset($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        $article->update($validated);

        if ($request->has('product_ids')) {
            $this->syncProducts($article, $request->input('product_ids', []));
        }

        return response()->json(['article' => $article->fresh('products')]);
    }

    public function destroy(Article $article)
    {
        $this->imageService->delete($article->thumbnail);
        $article->delete();

        return response()->json(['message' => 'مقاله با موفقیت حذف شد.']);
    }

    private function syncProducts(Article $article, array $productIds): void
    {
        $syncData = collect($productIds)
            ->values()
            ->mapWithKeys(fn($productId, $index) => [$productId => ['sort_order' => $index]])
            ->all();

        $article->products()->sync($syncData);
    }

    private function validateArticle(Request $request, ?Article $article = null): array
    {
        $sometimes = (bool) $article;
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'title' => "{$rule}|string|max:255",
            'excerpt' => 'nullable|string|max:500',
            'content' => "{$rule}|string",
            'thumbnail' => 'nullable|image|max:2048',
            'is_published' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        $data = $validator->validated();
        unset($data['thumbnail'], $data['product_ids']);

        return $data;
    }
}
