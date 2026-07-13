<?php

namespace App\Services;

use App\Models\ShippingRate;

/**
 * محاسبه‌ی هزینه‌ی ارسال بر اساس شهر مقصد و وزن کل سفارش (بند ۷ سند).
 * اگه برای شهر مشخصی نرخ اختصاصی تعریف نشده باشه، از نرخ پیش‌فرض
 * (city=null) استفاده می‌شه. اگه هیچ نرخی اصلاً تعریف نشده باشه، صفر
 * برمی‌گرده (یعنی هزینه‌ی ارسال فعلاً محاسبه نمی‌شه، نه اینکه خطا بده).
 */
class ShippingCostCalculator
{
    public function calculate(float $totalWeightKg, ?string $city): int
    {
        $rate = null;

        if ($city) {
            $rate = ShippingRate::where('city', $city)->first();
        }

        $rate ??= ShippingRate::whereNull('city')->first();

        if (! $rate) {
            return 0;
        }

        return (int) round($rate->base_price + $rate->price_per_kg * $totalWeightKg);
    }
}