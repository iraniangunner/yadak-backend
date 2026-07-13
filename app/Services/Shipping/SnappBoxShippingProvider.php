<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingProviderContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * پیاده‌سازی برای اسنپ‌باکس. این کلاس یه قالبه - بعد از گرفتن مستندات
 * واقعی API از اسنپ‌باکس، آدرس endpoint، اسم پارامترها، و ساختار پاسخ
 * (بخش‌های علامت‌گذاری‌شده با کامنت) رو با مستندات واقعی جایگزین کن.
 * اسنپ‌باکس معمولاً برای ارسال درون‌شهری/سریع استفاده می‌شه.
 */
class SnappBoxShippingProvider implements ShippingProviderContract
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.snappbox.api_key') ?? '';
        $this->baseUrl = config('services.snappbox.base_url', 'https://api.snappbox.ir');
    }

    public function getOptions(string $city, float $totalWeightKg): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(5)
                ->get("{$this->baseUrl}/v1/delivery-options", [
                    'city' => $city,
                    'weight_kg' => $totalWeightKg,
                ]);

            if (! $response->successful()) {
                Log::error('خطا در دریافت گزینه‌های اسنپ‌باکس', ['response' => $response->body()]);

                return [];
            }

            // ⚠️ این بخش رو دقیقاً بر اساس ساختار واقعی پاسخ API اسنپ‌باکس تنظیم کن
            return collect($response->json('services', []))->map(fn ($item) => [
                'carrier' => 'اسنپ‌باکس',
                'service_name' => $item['title'] ?? 'استاندارد',
                'cost' => (int) ($item['fee'] ?? 0),
                'eta_days' => (int) ceil(($item['eta_hours'] ?? 6) / 24), // اسنپ‌باکس معمولاً درون‌روزه/سریع
            ])->all();
        } catch (\Throwable $e) {
            Log::error('استثنا در اتصال به اسنپ‌باکس', ['error' => $e->getMessage()]);

            return [];
        }
    }
}