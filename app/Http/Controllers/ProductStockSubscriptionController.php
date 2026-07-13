<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStockSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductStockSubscriptionController extends Controller
{
    /**
     * ثبت علاقه‌مندی برای اطلاع از موجود شدن یک محصول (بند ۲.۳ سند).
     * هم برای کاربر لاگین‌شده (user_id ثبت می‌شه) هم مهمان (فقط mobile) کار می‌کنه.
     */
    public function store(Request $request, Product $product)
    {
        $user = $request->user(); // ممکنه null باشه (مهمان)

        $validator = Validator::make($request->all(), [
            'mobile' => [$user ? 'nullable' : 'required', 'regex:/^09[0-9]{9}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($product->isAvailable()) {
            return response()->json(['message' => 'این محصول همین الان موجود است.'], 422);
        }

        $exists = ProductStockSubscription::where('product_id', $product->id)
            ->where('notified', false)
            ->when($user, fn($q) => $q->where('user_id', $user->id))
            ->when(! $user, fn($q) => $q->where('mobile', $request->mobile))
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'قبلاً برای این محصول ثبت‌نام کرده‌اید.'], 422);
        }

        ProductStockSubscription::create([
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'mobile' => $user ? null : $request->mobile,
        ]);

        return response()->json(['message' => 'ثبت شد؛ به‌محض موجود شدن این کالا به شما اطلاع داده می‌شود.'], 201);
    }
}
