<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerVehicleController extends Controller
{
    /**
     * لیست خودروهای ثبت‌شده‌ی کاربر لاگین‌شده.
     */
    public function index(Request $request)
    {
        return response()->json([
            'vehicles' => $request->user()->vehicles,
        ]);
    }

    /**
     * ثبت یک خودرو برای کاربر لاگین‌شده (بند «ثبت خودروهای مشتری» سند).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:vehicles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // syncWithoutDetaching تا اگه از قبل ثبت شده بود، خطای duplicate نده
        $user->vehicles()->syncWithoutDetaching([$request->integer('vehicle_id')]);

        return response()->json([
            'message' => 'خودرو با موفقیت ثبت شد.',
            'vehicles' => $user->vehicles()->get(),
        ], 201);
    }

    /**
     * حذف یک خودروی ثبت‌شده از حساب کاربری (نه حذف خودِ خودرو از سیستم).
     */
    public function destroy(Request $request, Vehicle $vehicle)
    {
        $request->user()->vehicles()->detach($vehicle->id);

        return response()->json(['message' => 'خودرو از حساب شما حذف شد.']);
    }
}
