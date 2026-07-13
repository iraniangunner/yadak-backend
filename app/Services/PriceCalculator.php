<?php

namespace App\Services;

class PriceCalculator
{
    /**
     * رُند کردن قیمت به پایین (floor) به نزدیک‌ترین مضرب rounding_step.
     * عمداً floor (نه round معمولی) استفاده شده تا مشتری هیچ‌وقت بیشتر از
     * قیمت واقعیِ بعد از تخفیف پرداخت نکنه (بند ۳.۳ سند: «جلوگیری از نمایش
     * اعداد نامناسب»).
     *
     * مثال: rounding_step=1000 → قیمت 123456 می‌شه 123000.
     */
    public static function round(int $price): int
    {
        $step = (int) config('services.pricing.rounding_step', 1000);

        if ($step <= 1) {
            return $price;
        }

        return intdiv($price, $step) * $step;
    }
}
