<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductAttributeController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'value' => 'required|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
            'is_filterable' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attribute = $product->productAttributes()->create($validator->validated());

        return response()->json(['attribute' => $attribute], 201);
    }

    public function update(Request $request, Product $product, ProductAttribute $attribute)
    {
        if ($attribute->product_id !== $product->id) {
            return response()->json(['message' => 'این ویژگی متعلق به این محصول نیست.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'value' => 'sometimes|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
            'is_filterable' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attribute->update($validator->validated());

        return response()->json(['attribute' => $attribute]);
    }

    public function destroy(Product $product, ProductAttribute $attribute)
    {
        if ($attribute->product_id !== $product->id) {
            return response()->json(['message' => 'این ویژگی متعلق به این محصول نیست.'], 404);
        }

        $attribute->delete();

        return response()->json(['message' => 'ویژگی حذف شد.']);
    }
}
