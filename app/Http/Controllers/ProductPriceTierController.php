<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductPriceTierController extends Controller
{
    /**
     * ثبت یه بازه‌ی قیمت پلکانی جدید برای یه محصول.
     * مثال: {"min_quantity": 4, "max_quantity": null, "price": 90000}
     * یعنی «۴ عدد به بالا، هر کدوم ۹۰۰۰۰ تومان».
     */
    public function store(Request $request, Product $product)
    {
        $validated = $this->validateTier($request);

        if ($this->hasOverlap($product, $validated['min_quantity'], $validated['max_quantity'] ?? null)) {
            return response()->json([
                'message' => 'این بازه با یکی از بازه‌های قیمتی موجود همپوشانی دارد.',
            ], 422);
        }

        $tier = $product->priceTiers()->create($validated);

        return response()->json(['tier' => $tier], 201);
    }

    public function update(Request $request, Product $product, ProductPriceTier $tier)
    {
        if ($tier->product_id !== $product->id) {
            return response()->json(['message' => 'این بازه متعلق به این محصول نیست.'], 404);
        }

        $validated = $this->validateTier($request, sometimes: true);

        $minQuantity = $validated['min_quantity'] ?? $tier->min_quantity;
        $maxQuantity = array_key_exists('max_quantity', $validated) ? $validated['max_quantity'] : $tier->max_quantity;

        if ($this->hasOverlap($product, $minQuantity, $maxQuantity, excludeId: $tier->id)) {
            return response()->json([
                'message' => 'این بازه با یکی از بازه‌های قیمتی موجود همپوشانی دارد.',
            ], 422);
        }

        $tier->update($validated);

        return response()->json(['tier' => $tier->fresh()]);
    }

    public function destroy(Product $product, ProductPriceTier $tier)
    {
        if ($tier->product_id !== $product->id) {
            return response()->json(['message' => 'این بازه متعلق به این محصول نیست.'], 404);
        }

        $tier->delete();

        return response()->json(['message' => 'بازه‌ی قیمت پلکانی حذف شد.']);
    }

    private function validateTier(Request $request, bool $sometimes = false): array
    {
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'min_quantity' => "{$rule}|integer|min:1",
            'max_quantity' => 'nullable|integer|gte:min_quantity',
            'price' => "{$rule}|integer|min:0",
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }

    /**
     * چک می‌کنه آیا بازه‌ی [minQuantity, maxQuantity] با یکی از بازه‌های
     * موجودِ همین محصول تداخل داره یا نه. maxQuantity=null یعنی «بی‌نهایت».
     */
    private function hasOverlap(Product $product, int $minQuantity, ?int $maxQuantity, ?int $excludeId = null): bool
    {
        $newMax = $maxQuantity ?? PHP_INT_MAX;

        return $product->priceTiers()
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->contains(function (ProductPriceTier $existing) use ($minQuantity, $newMax) {
                $existingMax = $existing->max_quantity ?? PHP_INT_MAX;

                return $minQuantity <= $existingMax && $existing->min_quantity <= $newMax;
            });
    }
}
