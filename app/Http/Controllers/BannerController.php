<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BannerController extends Controller
{
    public function __construct(private ImageService $imageService) {}

    /**
     * لیست عمومی بنرهای فعال، مرتب‌شده برای نمایش توی کاروسل/بنر متحرک.
     */
    public function index(Request $request)
    {
        $banners = Banner::query()
            ->when(! $request->boolean('with_inactive'), fn($q) => $q->where('is_active', true))
            ->with('product')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $banners]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateBanner($request);

        $imagePath = $request->hasFile('image')
            ? $this->imageService->store($request->file('image'), 'banners')
            : null;

        if (! $imagePath) {
            return response()->json(['errors' => ['image' => ['عکس بنر الزامی است.']]], 422);
        }

        $banner = Banner::create([
            ...$validated,
            'image' => $imagePath,
        ]);

        return response()->json(['banner' => $banner->fresh('product')], 201);
    }

    public function update(Request $request, Banner $banner)
    {
        $validated = $this->validateBanner($request, sometimes: true);

        if ($request->hasFile('image')) {
            $validated['image'] = $this->imageService->replace(
                $banner->image,
                $request->file('image'),
                'banners'
            );
        }

        $banner->update($validated);

        return response()->json(['banner' => $banner->fresh('product')]);
    }

    public function destroy(Banner $banner)
    {
        $this->imageService->delete($banner->image);
        $banner->delete();

        return response()->json(['message' => 'بنر با موفقیت حذف شد.']);
    }

    private function validateBanner(Request $request, bool $sometimes = false): array
    {
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'title' => "{$rule}|string|max:255",
            'image' => 'nullable|image|max:2048',
            'product_id' => 'nullable|exists:products,id',
            'link_url' => 'nullable|url|max:500',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        $data = $validator->validated();
        unset($data['image']);

        return $data;
    }
}
