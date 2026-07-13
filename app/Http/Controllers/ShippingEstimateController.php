<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ShippingCostCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShippingEstimateController extends Controller
{
    public function __construct(private ShippingCostCalculator $calculator) {}

    /**
     * تخمین هزینه‌ی ارسال قبل از ثبت نهایی سفارش، بر اساس اقلام سبد خرید
     * و شهر مقصد (بند ۷ سند).
     */
    public function estimate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'city' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $products = Product::whereIn('id', collect($request->input('items'))->pluck('product_id'))
            ->get(['id', 'weight_kg'])
            ->keyBy('id');

        $totalWeight = collect($request->input('items'))->sum(
            fn($item) => (float) ($products[$item['product_id']]->weight_kg ?? 0) * $item['quantity']
        );

        $cost = $this->calculator->calculate($totalWeight, $request->input('city'));

        return response()->json([
            'total_weight_kg' => $totalWeight,
            'shipping_cost' => $cost,
        ]);
    }
}
