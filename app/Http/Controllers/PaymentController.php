<?php

namespace App\Http\Controllers;

use App\Contracts\AccountingProviderContract;
use App\Models\Order;
use App\Models\ReferralCommission;
use App\Services\SmsService;
use App\Services\ZarinpalService;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private ZarinpalService $zarinpal,
        private SmsService $sms,
        private AccountingProviderContract $accountingProvider,
    ) {}

    /**
     * ساخت لینک پرداخت زمان‌دار برای سفارشی که تایید شده (awaiting_payment).
     * فرانت بعد از این‌که سفارش تایید شد (approve یا confirm)، این endpoint
     * رو صدا می‌زنه تا آدرس صفحه‌ی پرداخت رو بگیره و کاربر رو بهش هدایت کنه.
     */


    // بعد (کل متد رو با این جایگزین کن):
    public function initiate(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این سفارش متعلق به شما نیست.'], 403);
        }

        // ⚠️ تغییر: expired هم قابل قبوله - یعنی مشتری می‌تونه بعد از انقضای
        // لینک قبلی، دوباره برای همون سفارش تلاش کنه (نیازی به سفارش جدید نیست).
        if (! in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_EXPIRED], true)) {
            return response()->json(['message' => 'این سفارش آماده‌ی پرداخت نیست.'], 422);
        }

        // اگه سفارش منقضی شده بود، دوباره فعالش می‌کنیم و پورسانت معرف (اگه
        // لغو شده بود) رو هم برمی‌گردونیم به حالت در انتظار.
        if ($order->status === Order::STATUS_EXPIRED) {
            $order->transitionTo(
                Order::STATUS_AWAITING_PAYMENT,
                $request->user(),
                'مشتری درخواست تلاش مجدد پرداخت داد؛ سفارش دوباره برای پرداخت فعال شد.'
            );

            $order->referralCommission?->update(['status' => ReferralCommission::STATUS_PENDING]);
        }

        // اگه لینک قبلی هنوز منقضی نشده، همون رو دوباره برگردون (authority جدید نساز)
        if ($order->payment_authority && $order->payment_link_expires_at?->isFuture()) {
            return response()->json([
                'payment_url' => $this->zarinpal->paymentUrl($order->payment_authority),
                'expires_at' => $order->payment_link_expires_at,
            ]);
        }

        try {
            $result = $this->zarinpal->requestPayment(
                amount: $order->total_amount,
                description: "پرداخت سفارش #{$order->id}",
                callbackUrl: route('payment.callback'),
                metadata: array_filter([
                    'mobile' => $order->user->phone,
                    'order_id' => (string) $order->id,
                ])
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $order->update([
            'payment_authority' => $result['authority'],
            'payment_link_expires_at' => now()->addMinutes(30),
        ]);

        return response()->json([
            'payment_url' => $result['payment_url'],
            'expires_at' => $order->payment_link_expires_at,
        ]);
    }

    /**
     * آدرسی که زرین‌پال بعد از پرداخت (موفق یا ناموفق) مرورگر کاربر رو
     * به اینجا redirect می‌کنه. این route عمومیه (بدون auth:api) چون
     * درخواست از مرورگر کاربر میاد، نه از فرانت با توکن.
     */
    public function callback(Request $request)
    {
        $authority = $request->query('Authority');
        $status = $request->query('Status');

        $frontendUrl = rtrim(config('services.frontend_url', 'http://localhost:3000'), '/');

        $order = Order::where('payment_authority', $authority)->first();

        if (! $order) {
            return redirect()->away("{$frontendUrl}/payment/result?status=not_found");
        }

        if ($order->isPaid()) {
            // احتمالاً کاربر صفحه رو رفرش کرده یا callback دوبار صدا خورده
            return redirect()->away("{$frontendUrl}/payment/result?status=already_paid&order_id={$order->id}");
        }

        if ($status !== 'OK') {
            // کاربر توی درگاه انصراف داده؛ authority رو پاک می‌کنیم تا دفعه‌ی
            // بعد /pay یه authority تازه بسازه (این authority دیگه قابل استفاده نیست)
            $order->update(['payment_authority' => null, 'payment_link_expires_at' => null]);
            $order->statusHistories()->create([
                'from_status' => $order->status,
                'to_status' => $order->status,
                'changed_by' => null,
                'note' => 'پرداخت توسط کاربر لغو شد یا ناموفق بود.',
            ]);

            return redirect()->away("{$frontendUrl}/payment/result?status=failed&order_id={$order->id}");
        }

        if ($order->payment_link_expires_at?->isPast()) {
            $order->transitionTo(Order::STATUS_EXPIRED, null, 'لینک پرداخت قبل از تکمیل تراکنش منقضی شده بود.');

            $order->referralCommission?->update(['status' => ReferralCommission::STATUS_CANCELLED]);

            return redirect()->away("{$frontendUrl}/payment/result?status=expired&order_id={$order->id}");
        }

        try {
            $verify = $this->zarinpal->verifyPayment($order->total_amount, $authority);
        } catch (RuntimeException $e) {
            $order->statusHistories()->create([
                'from_status' => $order->status,
                'to_status' => $order->status,
                'changed_by' => null,
                'note' => "تایید پرداخت ناموفق: {$e->getMessage()}",
            ]);

            return redirect()->away("{$frontendUrl}/payment/result?status=failed&order_id={$order->id}");
        }

        $order->update([
            'payment_ref_id' => $verify['ref_id'],
            'paid_at' => now(),
        ]);

        $order->transitionTo(Order::STATUS_PAID, null, "پرداخت با کد پیگیری {$verify['ref_id']} تایید شد.");

        // صدور فاکتور برخط از طریق نرم‌افزار حسابداری (بند ۷ سند).
        // اگه صدور فاکتور شکست بخوره، پرداخت مشتری همچنان معتبر می‌مونه؛
        // فقط خطا لاگ می‌شه تا ادمین بعداً بتونه دستی دوباره تلاش کنه
        // (از طریق endpoint /admin/orders/{order}/issue-invoice).
        try {
            $invoice = $this->accountingProvider->issueInvoice($order->fresh(['items', 'user']));

            $order->update([
                'invoice_number' => $invoice['invoice_number'],
                'invoice_url' => $invoice['invoice_url'] ?? null,
                'invoiced_at' => now(),
            ]);
        } catch (RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('صدور فاکتور خودکار شکست خورد', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        // پورسانت معرف/فروشنده فقط الان (که سفارش واقعاً پرداخت شده) قطعی
        // می‌شه - طبق سند: «محاسبه پورسانت... برای معرفی‌های موفق».
        if ($order->referralCommission) {
            $commissionAmount = $order->referralCode->calculateCommission($order->total_amount);

            $order->referralCommission->update([
                'commission_amount' => $commissionAmount,
                'status' => ReferralCommission::STATUS_APPROVED,
            ]);
        }

        // پیامک تایید پرداخت به مشتری + اعلان به انبار برای آماده‌سازی (بند ۴ سند)
        // نام الگو دقیقاً باید با چیزی که توی پنل کاوه‌نگار ساختی یکی باشه.
        // if ($order->user->phone) {
        //     $this->sms->sendByTemplate($order->user->phone, 'order-paid', [
        //         (string) $order->id,
        //         (string) $order->payment_ref_id,
        //     ]);
        // }
        if ($order->shipping_receiver_phone) {
            $this->sms->sendByTemplate(
                $order->shipping_receiver_phone,
                'order-paid',
                [(string) $order->id,  (string) $order->payment_ref_id,]
            );
        }


        $this->notifyStaff("سفارش #{$order->id} پرداخت شد (کد پیگیری {$order->payment_ref_id}) و آماده‌ی پردازش انبار است.");

        return redirect()->away("{$frontendUrl}/payment/result?status=success&order_id={$order->id}");
    }

    /**
     * اعلان داخلی به شماره‌ی(های) ادمین/انبار تنظیم‌شده توی .env (ADMIN_ALERT_MOBILE).
     */
    private function notifyStaff(string $message): void
    {
        $mobiles = collect(explode(',', config('services.admin_mobile', '')))
            ->map(fn($m) => trim($m))
            ->filter();

        foreach ($mobiles as $mobile) {
            $this->sms->send($mobile, $message);
        }
    }
}
