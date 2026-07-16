<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductReviewController extends Controller
{
    /**
     * لیست نظرات تأییدشده‌ی یه محصول (عمومی، بدون نیاز به لاگین).
     */
    public function index(Request $request, Product $product)
    {
        $reviews = $product->reviews()
            ->with('user:id,name')
            ->paginate($request->integer('per_page', 10));

        return response()->json($reviews);
    }

    /**
     * ثبت نظر برای یه محصول. اگه کاربر قبلاً نظر داده باشه، به‌جای خطای
     * تکراری، همون نظر قبلی‌ش رو آپدیت می‌کنه (یعنی هر کاربر فقط یه نظر
     * فعال روی هر محصول داره).
     */
    // بعد (هر نظر جدید یا ویرایش‌شده، دوباره pending می‌شه تا ادمین ببینتش):
    public function store(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review = ProductReview::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            [...$validator->validated(), 'is_approved' => false]
        );

        return response()->json([
            'review' => $review->load('user:id,name'),
            'message' => 'نظر شما ثبت شد و پس از بررسی ادمین نمایش داده می‌شود.',
        ], 201);
    }

    /**
     * حذف نظر خودِ کاربر.
     */
    public function destroy(Request $request, Product $product)
    {
        ProductReview::where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json(['message' => 'نظر شما حذف شد.']);
    }
}
