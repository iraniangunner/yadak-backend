<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function __construct(private ImageService $imageService)
    {
    }

    /**
     * لیست عمومی برندهای فعال (برای فیلتر توی صفحه‌ی محصولات).
     *
     * با ?category_id=1,2,3 فقط برندهایی برمی‌گردن که حداقل یه محصول
     * فعال توی اون دسته(ها) دارن - برای صفحه‌ی /category/[slug] که فقط
     * باید برندهای مرتبط با همون دسته رو توی فیلتر نشون بده.
     */
    public function index(Request $request)
    {
        $brands = Brand::query()
            ->when(! $request->boolean('with_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $categoryIds = array_filter(explode(',', $request->string('category_id')->toString()));
                $q->whereHas('products', function ($q) use ($categoryIds) {
                    $q->whereIn('category_id', $categoryIds)->where('is_active', true);
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($brands);
    }

    /**
     * ساخت برند جدید (فقط ادمین).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'thumbnail' => 'nullable|image|max:2048',
            'is_active' => 'sometimes|boolean',
            'sales_stopped' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $slug = Str::slug($request->name);

        if (Brand::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(4);
        }

        $thumbnailPath = $request->hasFile('thumbnail')
            ? $this->imageService->store($request->file('thumbnail'), 'brands')
            : null;

        $brand = Brand::create([
            'name' => $request->name,
            'slug' => $slug,
            'thumbnail' => $thumbnailPath,
            'is_active' => $request->boolean('is_active', true),
            'sales_stopped' => $request->boolean('sales_stopped', false),
        ]);

        return response()->json(['brand' => $brand], 201);
    }

    /**
     * ویرایش برند (فقط ادمین). اگه thumbnail جدید فرستاده بشه، قدیمی جایگزین می‌شه.
     */
    public function update(Request $request, Brand $brand)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'thumbnail' => 'nullable|image|max:2048',
            'is_active' => 'sometimes|boolean',
            'sales_stopped' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['thumbnail']);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] = $this->imageService->replace(
                $brand->thumbnail,
                $request->file('thumbnail'),
                'brands'
            );
        }

        $brand->update($data);

        return response()->json(['brand' => $brand->fresh()]);
    }

    /**
     * حذف برند (فقط ادمین). عکسش هم از دیسک پاک می‌شه.
     * توجه: محصولات مرتبط حذف نمی‌شن، فقط brand_id شون null می‌شه (nullOnDelete در migration).
     */
    public function destroy(Brand $brand)
    {
        $this->imageService->delete($brand->thumbnail);
        $brand->delete();

        return response()->json(['message' => 'برند با موفقیت حذف شد.']);
    }
}