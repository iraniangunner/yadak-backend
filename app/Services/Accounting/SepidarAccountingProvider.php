<?php

namespace App\Services\Accounting;

use App\Contracts\AccountingProviderContract;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * پیاده‌سازی برای نرم‌افزار حسابداری/فروشگاهی سپیدار. این کلاس یه قالبه -
 * API واقعی سپیدار (Sepidar Web API) یه فرآیند احراز هویت دومرحله‌ای داره
 * (رمزنگاری RSA برای گرفتن توکن + IntegrationID مخصوص هر مشتری) که باید
 * دقیقاً طبق مستندات رسمی سپیدار (که بعد از خرید لایسنس Web API بهتون
 * می‌دن) پیاده‌سازی بشه. اینجا فقط ساختار کلی (ورودی/خروجی) رو نشون دادم؛
 * قسمت احراز هویت رو باید با مستندات واقعی جایگزین کنید.
 */
class SepidarAccountingProvider implements AccountingProviderContract
{
    private string $baseUrl;
    private string $integrationId;

    public function __construct()
    {
        $this->baseUrl = config('services.sepidar.base_url', 'http://localhost:7373/api');
        $this->integrationId = config('services.sepidar.integration_id') ?? '';
    }

    public function issueInvoice(Order $order): array
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::withToken($token)
                ->withHeaders([
                    'GenerationVersion' => '2.4',
                    'IntegrationID' => $this->integrationId,
                    // ⚠️ سپیدار برای هر درخواست به یه هدر ArbitraryCode امضاشده
                    // نیاز داره که باید طبق مستندات رسمی (رمزنگاری با کلید
                    // اختصاصی) تولید بشه - این بخش رو با مستندات واقعی جایگزین کن.
                ])
                ->timeout(10)
                ->post("{$this->baseUrl}/Invoices", $this->buildInvoicePayload($order));

            if (! $response->successful()) {
                throw new RuntimeException("خطای سپیدار: {$response->body()}");
            }

            return [
                'invoice_number' => (string) $response->json('InvoiceNumber'),
                'invoice_url' => $response->json('PrintUrl'),
            ];
        } catch (\Throwable $e) {
            Log::error('خطا در صدور فاکتور سپیدار', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('صدور فاکتور با خطا مواجه شد: ' . $e->getMessage());
        }
    }

    /**
     * ⚠️ این متد فقط یه Placeholder هست. فرآیند واقعی گرفتن توکن از سپیدار
     * نیاز به رمزنگاری RSA با کلید عمومی سرور سپیدار داره که مستندات دقیقش
     * رو باید از خودشون (بعد از خرید لایسنس Web API) بگیرید.
     */
    private function getAccessToken(): string
    {
        return config('services.sepidar.cached_token') ?? '';
    }

    /**
     * ساخت بدنه‌ی درخواست فاکتور از روی آیتم‌های سفارش.
     * ⚠️ اسم فیلدها (ItemID, Quantity, UnitPrice و...) باید دقیقاً با
     * ساختار واقعی API سپیدار و کدهای کالای تعریف‌شده توی خودِ سپیدار تطبیق
     * داده بشه (احتمالاً نیاز به نگاشت SKU سایت به ItemID سپیدار دارید).
     */
    private function buildInvoicePayload(Order $order): array
    {
        return [
            'CustomerName' => $order->shipping_receiver_name ?? $order->user->name,
            'CustomerMobile' => $order->user->phone,
            'Lines' => $order->items->map(fn($item) => [
                'ItemSKU' => $item->sku,
                'Quantity' => $item->quantity,
                'UnitPrice' => $item->price,
            ])->all(),
            'TotalAmount' => $order->total_amount,
            'ExternalOrderID' => (string) $order->id,
        ];
    }
}
