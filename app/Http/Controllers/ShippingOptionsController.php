<?php

namespace App\Http\Controllers;

use App\Contracts\ShippingProviderContract;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShippingOptionsController extends Controller
{
    public function __construct(private ShippingProviderContract $shippingProvider) {}

    /**
     * لیست گزینه‌های حمل (چند شرکت، هر کدوم با هزینه و زمان تحویل)
     * برای یه سبد خرید و شهر مقصد مشخص (بند ۷ سند).
     */
    public function index(Request $request)
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

        $options = $this->shippingProvider->getOptions($request->input('city'), $totalWeight);

        return response()->json([
            'total_weight_kg' => $totalWeight,
            'options' => $options,
        ]);
    }
}
