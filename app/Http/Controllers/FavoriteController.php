<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFavorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * لیست محصولات علاقه‌مندی کاربر لاگین‌شده.
     */
    public function index(Request $request)
    {
        $favorites = ProductFavorite::where('user_id', $request->user()->id)
            ->with(['product.brand', 'product.category'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($favorites);
    }

    /**
     * افزودن یه محصول به علاقه‌مندی‌ها. اگه از قبل بود، خطا نمی‌ده
     * (idempotent) - چون فرانت با toggle کار می‌کنه.
     */
    public function store(Request $request, Product $product)
    {
        ProductFavorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return response()->json(['message' => 'به علاقه‌مندی‌ها اضافه شد.'], 201);
    }

    /**
     * حذف یه محصول از علاقه‌مندی‌ها.
     */
    public function destroy(Request $request, Product $product)
    {
        ProductFavorite::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json(['message' => 'از علاقه‌مندی‌ها حذف شد.']);
    }
}
