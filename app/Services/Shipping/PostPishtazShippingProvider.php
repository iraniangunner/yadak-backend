<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * پیاده‌سازی برای پست پیشتاز (شرکت ملی پست ایران). این کلاس یه قالبه -
 * بعد از گرفتن مستندات واقعی API از پست، آدرس endpoint، اسم پارامترها،
 * و ساختار پاسخ (بخش‌های علامت‌گذاری‌شده) رو با مستندات واقعی جایگزین کن.
 */
class PostPishtazShippingProvider implements ShippingProviderContract
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.post_pishtaz.api_key') ?? '';
        $this->baseUrl = config('services.post_pishtaz.base_url', 'https://api.post.ir');
    }

    // public function getOptions(string $city, float $totalWeightKg): array
    // {
    //     try {
    //         $response = Http::withToken($this->apiKey)
    //             ->timeout(5)
    //             ->get("{$this->baseUrl}/v1/tariff", [
    //                 'destination_city' => $city,
    //                 'weight_kg' => $totalWeightKg,
    //                 'service' => 'pishtaz',
    //             ]);

    //         if (! $response->successful()) {
    //             Log::error('خطا در دریافت نرخ پست پیشتاز', ['response' => $response->body()]);

    //             return [];
    //         }

    //         // ⚠️ این بخش رو دقیقاً بر اساس ساختار واقعی پاسخ API پست تنظیم کن
    //         return [[
    //             'carrier' => 'پست',
    //             'service_name' => 'پیشتاز',
    //             'cost' => (int) $response->json('price', 0),
    //             'eta_days' => (int) $response->json('delivery_days', 3),
    //         ]];
    //     } catch (\Throwable $e) {
    //         Log::error('استثنا در اتصال به پست پیشتاز', ['error' => $e->getMessage()]);

    //         return [];
    //     }
    // }

    public function getOptions(string $city, float $totalWeightKg): array
    {
        return [
            [
                'carrier' => 'پست',
                'service_name' => 'پیشتاز',
                'cost' => 65000,
                'eta_days' => 3,
            ]
        ];
    }
}
