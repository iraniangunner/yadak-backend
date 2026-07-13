<?php

namespace App\Contracts;

/**
 * قرارداد اتصال به شرکت‌های حمل و نقل (بند ۷ سند: «اتصال به سامانه‌های
 * حمل و ارسال بار برای نمایش گزینه‌های حمل و هزینه آن به مشتری»).
 *
 * وقتی قرارداد واقعی با یه شرکت حمل (پست، تیپاکس، اسنپ‌باکس و...) بسته
 * شد، کافیه یه کلاس جدید implements این interface بنویسی که واقعاً به
 * API اون شرکت وصل بشه، و توی AppServiceProvider بایندینگ رو عوض کنی -
 * نه Controller نه فرآیند سفارش نیاز به تغییر دارن.
 */
interface ShippingProviderContract
{
    /**
     * لیست گزینه‌های حمل موجود برای یه مقصد و وزن مشخص.
     *
     * @return array<int, array{carrier: string, service_name: string, cost: int, eta_days: int}>
     */
    public function getOptions(string $city, float $totalWeightKg): array;
}
