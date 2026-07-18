<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\PersianSlug;

class ProductController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    /**
     * لیست عمومی محصولات با فیلتر. مهم‌ترین فیلتر طبق بند ۲.۳ سند:
     * ?vehicle_id=X → فقط محصولاتی که به این خودرو تگ شدن.
     * بقیه‌ی فیلترها: category_id, brand_id, stock_status, search (روی title/sku).
     */
    public function index(Request $request)
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($request->filled('vehicle_id'), function ($q) use ($request) {
                $q->whereHas('vehicles', fn ($q) => $q->where('vehicles.id', $request->integer('vehicle_id')));
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $ids = array_filter(explode(',', $request->string('category_id')->toString()));
                $q->whereIn('category_id', $ids);
            })
            ->when($request->filled('brand_id'), function ($q) use ($request) {
                $ids = array_filter(explode(',', $request->string('brand_id')->toString()));
                $q->whereIn('brand_id', $ids);
            })
            ->when($request->filled('stock_status'), function ($q) use ($request) {
                $statuses = array_filter(explode(',', $request->string('stock_status')->toString()));
                $q->whereIn('stock_status', $statuses);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            // میانگین امتیاز به‌عنوان یه ستون واقعی SQL (reviews_avg_rating) -
            // چون فقط این‌جوری می‌شه هم فیلترش کرد هم مرتبش کرد.
            ->withAvg('reviews', 'rating')
            ->when($request->filled('min_rating'), function ($q) use ($request) {
                $q->having('reviews_avg_rating', '>=', $request->float('min_rating'));
            })
            ->with(['brand:id,name,slug', 'category:id,name,slug']);
    
        $products = match ($request->string('sort')->toString()) {
            'price_asc' => $products->orderBy('price', 'asc'),
            'price_desc' => $products->orderBy('price', 'desc'),
            'rating' => $products->orderByDesc('reviews_avg_rating'),
            'newest' => $products->orderByDesc('created_at'),
            default => $products->orderBy('title'),
        };
    
        $products = $products->paginate($request->integer('per_page', 24));
    
        return response()->json($products);
    }

    /**
     * نمایش یک محصول همراه با گالری، خودروهای مرتبط، برند و دسته‌بندی.
     */
    public function show(Product $product)
    {
        $product->load(['images', 'vehicles', 'brand', 'category', 'productAttributes']);

        // چک اینکه آیا کاربر لاگین‌شده (اگه لاگین باشه) این محصول رو
        // علاقه‌مندی کرده یا نه - برای نمایش وضعیت اولیه‌ی دکمه‌ی قلب.
        $isFavorited = false;
        if ($userId = auth('api')->id()) {
            $isFavorited = \App\Models\ProductFavorite::where('user_id', $userId)
                ->where('product_id', $product->id)
                ->exists();
        }

        return response()->json([
            'product' => $product,
            'is_favorited' => $isFavorited,
        ]);
    }
    /**
     * قیمت واحد و جمع کل برای یه تعداد مشخص، با در نظر گرفتن قیمت پلکانی
     * (اگه تعریف شده باشه) و تخفیف فعال. برای سبد خرید فرانت که می‌خواد
     * قبل از ثبت سفارش قیمت دقیق رو نشون بده.
     */
    public function priceForQuantity(Request $request, Product $product)
    {
        $validator = validator($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $quantity = $request->integer('quantity');
        $unitPrice = $product->priceForQuantity($quantity);

        return response()->json([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $unitPrice * $quantity,
        ]);
    }

    /**
     * ساخت محصول جدید (ادمین/انبار). thumbnail و گالری اختیاری هستن،
     * ولی می‌شه همون لحظه هم فرستاد (multipart/form-data).
     */
    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        // $slug = Str::slug($validated['title']);
        $slug = PersianSlug::make($validated['title']);

        if (Product::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $thumbnailPath = $request->hasFile('thumbnail')
            ? $this->imageService->store($request->file('thumbnail'), 'products')
            : null;

        $product = Product::create([
            ...$validated,
            'slug' => $slug,
            'thumbnail' => $thumbnailPath,
        ]);

        if ($request->hasFile('images')) {
            $this->storeGalleryImages($product, $request->file('images'));
        }

        if ($request->filled('vehicle_ids')) {
            $product->vehicles()->sync($request->input('vehicle_ids'));
        }

        return response()->json(['product' => $product->fresh(['images', 'vehicles'])], 201);
    }

    /**
     * ویرایش محصول. اگه vehicle_ids فرستاده بشه (حتی آرایه‌ی خالی)، لیست
     * خودروهای مرتبط با sync جایگزین می‌شه (نه اضافه‌شدن به قبلی‌ها).
     */
    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request, $product);

        // اگه عنوان جدید فرستاده شده و واقعاً با عنوان قبلی فرق داره، slug
        // رو دوباره از روی عنوان جدید بساز (و باز هم چک یکتا بودن رو انجام بده).
        if (array_key_exists('title', $validated) && $validated['title'] !== $product->title) {
            $slug = PersianSlug::make($validated['title']);

            if (Product::where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                $slug .= '-' . \Illuminate\Support\Str::random(4);
            }

            $validated['slug'] = $slug;
        }

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $this->imageService->replace(
                $product->thumbnail,
                $request->file('thumbnail'),
                'products'
            );
        }

        $product->update($validated);

        if ($request->hasFile('images')) {
            $this->storeGalleryImages($product, $request->file('images'));
        }

        if ($request->has('vehicle_ids')) {
            $product->vehicles()->sync($request->input('vehicle_ids', []));
        }

        return response()->json(['product' => $product->fresh(['images', 'vehicles'])]);
    }
    /**
     * حذف محصول همراه با پاک‌سازی فایل‌های فیزیکی thumbnail و گالری از دیسک.
     * ردیف‌های product_images و product_vehicle خودکار با cascadeOnDelete پاک می‌شن.
     */
    public function destroy(Product $product)
    {
        $this->imageService->delete($product->thumbnail);

        foreach ($product->images as $image) {
            $this->imageService->delete($image->path);
        }

        $product->delete();

        return response()->json(['message' => 'محصول با موفقیت حذف شد.']);
    }

    /**
     * حذف یک عکس مشخص از گالری محصول (بدون حذف کل محصول).
     */
    public function destroyImage(Product $product, ProductImage $image)
    {
        if ($image->product_id !== $product->id) {
            return response()->json(['message' => 'این عکس متعلق به این محصول نیست.'], 404);
        }

        $this->imageService->delete($image->path);
        $image->delete();

        return response()->json(['message' => 'عکس با موفقیت حذف شد.']);
    }

    private function storeGalleryImages(Product $product, array $files): void
    {
        $nextOrder = (int) $product->images()->max('sort_order') + 1;

        foreach ($files as $file) {
            $path = $this->imageService->store($file, 'products/gallery');

            $product->images()->create([
                'path' => $path,
                'sort_order' => $nextOrder++,
            ]);
        }
    }

    /**
     * @param  Product|null  $product  فقط موقع update پاس داده می‌شه؛ برای اینکه
     *                                 unique rule روی sku، خودِ رکورد فعلی رو نادیده بگیره.
     */
    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $sometimes = (bool) $product;
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'title' => "{$rule}|string|max:255",
            'sku' => [
                $rule,
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($product?->id),
            ],
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'description' => 'nullable|string',
            'price' => "{$rule}|integer|min:0",
            'compare_price' => 'nullable|integer|min:0|gte:price',
            'stock_status' => 'sometimes|in:available,stopped,out_of_stock,incoming',
            'weight_kg' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:255',
            'package_type' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'thumbnail' => 'nullable|image|max:2048',
            'images.*' => 'nullable|image|max:2048',
            'vehicle_ids' => 'nullable|array',
            'vehicle_ids.*' => 'exists:vehicles,id',
        ], [
            'compare_price.gte' => 'قیمت قبل از تخفیف باید بزرگ‌تر یا مساوی قیمت فعلی باشد.',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        $data = $validator->validated();

        unset($data['thumbnail'], $data['images'], $data['vehicle_ids']);

        return $data;
    }


    public function adminIndex(Request $request)
    {
        $products = Product::query()
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('stock_status'), fn($q) => $q->where('stock_status', $request->string('stock_status')))
            ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->with(['brand:id,name,slug', 'category:id,name,slug'])
            ->orderBy('title')
            ->paginate($request->integer('per_page', 20));

        return response()->json($products);
    }
}
