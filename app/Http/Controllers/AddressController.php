<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller
{
    /**
     * لیست آدرس‌های خودِ مشتری لاگین‌شده.
     */
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->orderByDesc('is_default')->latest()->get();

        return response()->json(['data' => $addresses]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAddress($request);

        if ($validated['is_default'] ?? false) {
            $request->user()->addresses()->update(['is_default' => false]);
        }

        $address = $request->user()->addresses()->create($validated);

        return response()->json(['address' => $address], 201);
    }

    public function update(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این آدرس متعلق به شما نیست.'], 403);
        }

        $validated = $this->validateAddress($request, sometimes: true);

        if ($validated['is_default'] ?? false) {
            $request->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json(['address' => $address->fresh()]);
    }

    public function destroy(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این آدرس متعلق به شما نیست.'], 403);
        }

        $address->delete();

        return response()->json(['message' => 'آدرس با موفقیت حذف شد.']);
    }

    private function validateAddress(Request $request, bool $sometimes = false): array
    {
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:100',
            'receiver_name' => "{$rule}|string|max:255",
            'receiver_phone' => "{$rule}|regex:/^09[0-9]{9}$/",
            'province' => 'nullable|string|max:255',
            'city' => "{$rule}|string|max:255",
            'full_address' => "{$rule}|string|max:1000",
            'postal_code' => 'nullable|string|max:20',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
