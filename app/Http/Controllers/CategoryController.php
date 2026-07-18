<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Support\PersianSlug;

class CategoryController extends Controller
{
    public function __construct(private ImageService $imageService)
    {
    }

    /**
     * لیست عمومی دسته‌بندی‌ها. با ?tree=1 به‌صورت درختی (فقط ریشه‌ها + children)
     * برمی‌گرده، وگرنه لیست تخت با pagination.
     */
    public function index(Request $request)
    {
        if ($request->boolean('tree')) {
            $categories = Category::with('children')
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return response()->json(['data' => $categories]);
        }

        $categories = Category::query()
            ->when(! $request->boolean('with_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 50));

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'thumbnail' => 'nullable|image|max:2048',
            'is_active' => 'sometimes|boolean',
            'sales_stopped' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // $slug = Str::slug($request->name);

        $slug = PersianSlug::make($request->name);

        if (Category::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $thumbnailPath = $request->hasFile('thumbnail')
            ? $this->imageService->store($request->file('thumbnail'), 'categories')
            : null;

        $category = Category::create([
            'parent_id' => $request->parent_id,
            'name' => $request->name,
            'slug' => $slug,
            'thumbnail' => $thumbnailPath,
            'is_active' => $request->boolean('is_active', true),
            'sales_stopped' => $request->boolean('sales_stopped', false),
            'sort_order' => $request->integer('sort_order', 0),
        ]);

        return response()->json(['category' => $category], 201);
    }

    public function update(Request $request, Category $category)
    {
        // ⚠️ چون فرانت ممکنه parent_id رو به‌صورت رشته‌ی خالی بفرسته (یعنی
        // «بدون والد»)، و قانون nullable فقط null واقعی رو قبول می‌کنه
        // (نه رشته‌ی خالی)، قبل از اعتبارسنجی نرمالش می‌کنیم.
        if ($request->has('parent_id') && $request->input('parent_id') === '') {
            $request->merge(['parent_id' => null]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'parent_id' => [
                'nullable',
                'exists:categories,id',
                function ($attribute, $value, $fail) use ($category) {
                    if ($value == $category->id) {
                        $fail('یک دسته‌بندی نمی‌تواند زیرمجموعه‌ی خودش باشد.');
                    }
                },
            ],
            'thumbnail' => 'nullable|image|max:2048',
            'is_active' => 'sometimes|boolean',
            'sales_stopped' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['thumbnail']);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $this->imageService->replace(
                $category->thumbnail,
                $request->file('thumbnail'),
                'categories'
            );
        }

        $category->update($data);

        return response()->json(['category' => $category->fresh()]);
    }

    /**
     * حذف دسته‌بندی. زیردسته‌ها به‌خاطر nullOnDelete در migration، parent_id شون
     * null می‌شه (یعنی به دسته‌ی ریشه تبدیل می‌شن)، نه اینکه حذف بشن.
     */
    public function destroy(Category $category)
    {
        $this->imageService->delete($category->thumbnail);
        $category->delete();

        return response()->json(['message' => 'دسته‌بندی با موفقیت حذف شد.']);
    }
}
