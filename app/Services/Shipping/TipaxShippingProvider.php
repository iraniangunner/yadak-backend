<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * پیاده‌سازی واقعی برای تیپاکس. این کلاس رو با مستندات API واقعی تیپاکس
 * (که بعد از عقد قرارداد بهتون می‌دن) تطبیق بدید - اسم فیلدها، آدرس
 * endpoint، و ساختار پاسخ فرضیه و باید با API واقعی جایگزین بشه.
 */
class TipaxShippingProvider implements ShippingProviderContract
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.tipax.api_key') ?? '';
        $this->baseUrl = config('services.tipax.base_url', 'https://api.tipax.ir');
    }

    // public function getOptions(string $city, float $totalWeightKg): array
    // {
    //     try {
    //         $response = Http::withToken($this->apiKey)
    //             ->timeout(5)
    //             ->get("{$this->baseUrl}/v1/shipping-options", [
    //                 'destination_city' => $city,
    //                 'weight_kg' => $totalWeightKg,
    //             ]);

    //         if (! $response->successful()) {
    //             Log::error('خطا در دریافت گزینه‌های تیپاکس', ['response' => $response->body()]);

    //             return [];
    //         }

    //         // این بخش رو دقیقاً بر اساس ساختار واقعی پاسخ API تیپاکس تنظیم کن
    //         return collect($response->json('data', []))->map(fn ($item) => [
    //             'carrier' => 'تیپاکس',
    //             'service_name' => $item['service_name'],
    //             'cost' => (int) $item['price'],
    //             'eta_days' => (int) $item['estimated_days'],
    //         ])->all();
    //     } catch (\Throwable $e) {
    //         Log::error('استثنا در اتصال به تیپاکس', ['error' => $e->getMessage()]);

    //         return [];
    //     }
    // }

   public function getOptions(string $city, float $totalWeightKg): array
{
    return [
        [
            'carrier' => 'تیپاکس',
            'service_name' => 'ارسال عادی',
            'cost' => 85000,
            'eta_days' => 2,
        ]
    ];
}
}
