<?php

namespace App\Http\Controllers;

use App\Models\ShippingRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShippingRateController extends Controller
{
    public function index()
    {
        return response()->json(['data' => ShippingRate::orderBy('city')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateRate($request);

        $rate = ShippingRate::create($validated);

        return response()->json(['rate' => $rate], 201);
    }

    public function update(Request $request, ShippingRate $rate)
    {
        $validated = $this->validateRate($request, $rate);

        $rate->update($validated);

        return response()->json(['rate' => $rate->fresh()]);
    }

    public function destroy(ShippingRate $rate)
    {
        $rate->delete();

        return response()->json(['message' => 'نرخ ارسال حذف شد.']);
    }

    private function validateRate(Request $request, ?ShippingRate $rate = null): array
    {
        $sometimes = (bool) $rate;
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'city' => ['nullable', 'string', 'max:255', Rule::unique('shipping_rates', 'city')->ignore($rate?->id)],
            'base_price' => "{$rule}|integer|min:0",
            'price_per_kg' => "{$rule}|integer|min:0",
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
