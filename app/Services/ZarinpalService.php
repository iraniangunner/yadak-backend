<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * سرویس ارتباط با درگاه زرین‌پال (REST API v4).
 *
 * نکته‌ی مهم درباره‌ی واحد پول: API v4 زرین‌پال مبلغ رو به «تومان» می‌گیره
 * (نه ریال). اگه ستون‌های price/total_amount توی دیتابیس شما ریالی هستن،
 * قبل از فراخوانی این سرویس باید تقسیم بر ۱۰ کنید. فعلاً فرض شده مبلغ‌ها
 * از همون اول تومانی ذخیره شدن (که رایج‌تره برای فروشگاه‌های ایرانی).
 */
class ZarinpalService
{
    protected string $merchantId;
    protected bool $sandbox;

    public function __construct()
    {
        $this->merchantId = config('services.zarinpal.merchant_id');
        $this->sandbox = (bool) config('services.zarinpal.sandbox', true);
    }

    protected function baseUrl(): string
    {
        return $this->sandbox
            ? 'https://sandbox.zarinpal.com'
            : 'https://api.zarinpal.com';
    }

    /**
     * درخواست ساخت یک تراکنش پرداخت جدید. اگه موفق باشه، authority و
     * آدرس کامل صفحه‌ی پرداخت رو برمی‌گردونه.
     *
     * @throws RuntimeException اگه زرین‌پال خطا برگردونه (مثلاً merchant_id نامعتبر)
     */
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $metadata = []): array
    {
        $response = Http::post("{$this->baseUrl()}/pg/v4/payment/request.json", [
            'merchant_id' => $this->merchantId,
            'amount' => $amount,
            'description' => $description,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        $data = $response->json('data');

        if (! $response->successful() || ! $data || ($data['code'] ?? null) !== 100) {
            $errorMessage = $response->json('errors.message') ?? 'خطای نامشخص از زرین‌پال';
            throw new RuntimeException("خطا در ایجاد لینک پرداخت: {$errorMessage}");
        }

        return [
            'authority' => $data['authority'],
            'payment_url' => $this->paymentUrl($data['authority']),
        ];
    }

    /**
     * ساخت آدرس صفحه‌ی پرداخت از روی authority (برای redirect کاربر).
     */
    public function paymentUrl(string $authority): string
    {
        return "{$this->baseUrl()}/pg/StartPay/{$authority}";
    }

    /**
     * تایید یک تراکنش بعد از بازگشت کاربر از درگاه.
     * code=100 یعنی تایید موفق تازه؛ code=101 یعنی قبلاً هم تایید شده بود
     * (هر دو را موفق در نظر می‌گیریم تا با رفرش صفحه دوباره خطا ندیم).
     *
     * @throws RuntimeException اگه پرداخت ناموفق/نامعتبر باشه
     */
    public function verifyPayment(int $amount, string $authority): array
    {
        $response = Http::post("{$this->baseUrl()}/pg/v4/payment/verify.json", [
            'merchant_id' => $this->merchantId,
            'amount' => $amount,
            'authority' => $authority,
        ]);

        $data = $response->json('data');
        $code = $data['code'] ?? null;

        if (! in_array($code, [100, 101], true)) {
            $errorMessage = $response->json('errors.message') ?? 'پرداخت تایید نشد';
            throw new RuntimeException($errorMessage);
        }

        return [
            'ref_id' => $data['ref_id'] ?? null,
            'code' => $code,
        ];
    }
}
