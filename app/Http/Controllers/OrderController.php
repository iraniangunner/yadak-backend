<?php

namespace App\Http\Controllers;

use App\Contracts\ShippingProviderContract;
use App\Models\Address;
use App\Models\CartDiscountRule;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralCode;
use App\Models\ReferralCommission;
use App\Services\PriceCalculator;
use App\Services\ShippingCostCalculator;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function __construct(
        private SmsService $sms,
        private ShippingCostCalculator $shippingCalculator,
        private ShippingProviderContract $shippingProvider,
    ) {}

    /**
     * لیست سفارش‌های خودِ مشتری لاگین‌شده.
     */
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->with('items')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($orders);
    }

    /**
     * نمایش یک سفارش - فقط اگه متعلق به خودِ کاربر باشه.
     */
    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این سفارش متعلق به شما نیست.'], 403);
        }

        $order->load(['items', 'statusHistories']);

        return response()->json(['order' => $order]);
    }

    /**
     * ثبت اولیه‌ی سفارش از سبد خرید (بند ۴ سند).
     * پرداخت در همین لحظه انجام نمی‌شه؛ سفارش توی وضعیت pending_review می‌مونه
     * تا انبار/ادمین موجودی رو بررسی کنه.
     *
     * ورودی: items: [{product_id, quantity}, ...]
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_note' => 'nullable|string|max:1000',
            'coupon_code' => 'nullable|string|max:50',
            'referral_code' => 'nullable|string|max:50',
            'shipping_address_id' => 'nullable|exists:addresses,id',
            'shipping_carrier' => 'nullable|string|required_with:shipping_service_name',
            'shipping_service_name' => 'nullable|string|required_with:shipping_carrier',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productIds = collect($request->input('items'))->pluck('product_id')->unique();
        $products = Product::with(['category:id,sales_stopped', 'brand:id,sales_stopped'])
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        // اگه یکی از محصولات دیگه فعال/موجود توی سیستم نیست، سفارش رو رد می‌کنیم
        // (بهتره خودِ سبد خرید سمت فرانت این چک رو انجام بده، ولی سرور هم دوباره چک می‌کنه).
        $missing = $productIds->diff($products->keys());
        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'برخی از کالاهای انتخاب‌شده دیگر در دسترس نیستند.',
                'unavailable_product_ids' => $missing->values(),
            ], 422);
        }

        // چک توقف فروش موردی (stock_status) یا دسته‌ای (دسته‌بندی/برند) - بند ۵ سند.
        $unpurchasable = $products->filter(fn(Product $product) => ! $product->isPurchasable());
        if ($unpurchasable->isNotEmpty()) {
            return response()->json([
                'message' => 'فروش برخی از کالاهای انتخاب‌شده موقتاً متوقف شده است.',
                'unavailable_product_ids' => $unpurchasable->keys()->values(),
            ], 422);
        }

        // اعتبارسنجی کد تخفیف *قبل* از شروع تراکنش، چون اگه نامعتبر بود
        // نباید اصلاً سفارشی ساخته بشه.
        $coupon = null;
        if ($request->filled('coupon_code')) {
            $subtotalForCouponCheck = collect($request->input('items'))->sum(
                fn($item) => $products[$item['product_id']]->priceForQuantity($item['quantity']) * $item['quantity']
            );

            $couponResult = $this->resolveCoupon($request->string('coupon_code')->toString(), $subtotalForCouponCheck, $request->user()->id);

            if ($couponResult['error']) {
                return response()->json(['message' => $couponResult['error']], 422);
            }

            $coupon = $couponResult['coupon'];
        }

        // کد معرف/فروشنده - برخلاف کد تخفیف، قیمت مشتری رو کم نمی‌کنه؛
        // فقط برای محاسبه‌ی پورسانت معرف استفاده می‌شه.
        $referralCode = null;
        if ($request->filled('referral_code')) {
            $referralCode = ReferralCode::active()
                ->where('code', strtoupper(trim($request->string('referral_code')->toString())))
                ->first();

            if (! $referralCode) {
                return response()->json(['message' => 'کد معرف نامعتبر است.'], 422);
            }
        }

        // اگه آدرس ارسال داده شده، مطمئن شو متعلق به خودِ همین مشتریه
        $shippingAddress = null;
        if ($request->filled('shipping_address_id')) {
            $shippingAddress = Address::where('id', $request->input('shipping_address_id'))
                ->where('user_id', $request->user()->id)
                ->first();

            if (! $shippingAddress) {
                return response()->json(['message' => 'آدرس ارسال معتبر نیست.'], 422);
            }
        }

        // اگه مشتری یه گزینه‌ی حمل مشخص (شرکت + نوع سرویس) انتخاب کرده،
        // دوباره از provider استعلام می‌گیریم و مطمئن می‌شیم دقیقاً همون
        // گزینه هنوز معتبره - هیچ‌وقت به هزینه‌ای که از سمت کلاینت میاد
        // اعتماد نمی‌کنیم، خودمون دوباره محاسبه می‌کنیم.
        $selectedShippingOption = null;
        if ($shippingAddress && $request->filled('shipping_carrier')) {
            $totalWeightForOptions = collect($request->input('items'))->sum(
                fn($item) => (float) ($products[$item['product_id']]->weight_kg ?? 0) * $item['quantity']
            );

            $options = $this->shippingProvider->getOptions($shippingAddress->city, $totalWeightForOptions);

            $selectedShippingOption = collect($options)->first(
                fn($option) => $option['carrier'] === $request->input('shipping_carrier')
                    && $option['service_name'] === $request->input('shipping_service_name')
            );

            if (! $selectedShippingOption) {
                return response()->json(['message' => 'گزینه‌ی حمل انتخاب‌شده دیگر معتبر نیست.'], 422);
            }
        }

        $order = DB::transaction(function () use ($request, $products, $coupon, $referralCode, $shippingAddress, $selectedShippingOption) {
            $subtotal = 0;
            $totalWeightKg = 0;

            $itemsData = collect($request->input('items'))->map(function ($item) use ($products, &$subtotal, &$totalWeightKg) {
                $product = $products[$item['product_id']];
                $unitPrice = $product->priceForQuantity($item['quantity']);
                $lineTotal = $unitPrice * $item['quantity'];
                $subtotal += $lineTotal;
                $totalWeightKg += (float) ($product->weight_kg ?? 0) * $item['quantity'];

                return [
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'price' => $unitPrice,
                    'quantity' => $item['quantity'],
                    'is_available' => true, // بررسی واقعی موجودی رو انبار انجام می‌ده
                ];
            });

            $discountAmount = $coupon ? $coupon->calculateDiscount($subtotal) : 0;

            // ⚠️ جدید: تخفیف خودکار سبد بر اساس عبور از مبلغ (بند ۳.۳ سند).
            // بین همه‌ی قوانین فعالی که subtotal ازشون رد شده، بیشترین
            // حدنصاب (min_amount) رو انتخاب می‌کنیم - نه اولین موردی که پیدا شد.
            // این تخفیف مستقل از کد تخفیفه و باهاش جمع می‌شه (نه جایگزینش).
            $cartDiscountRule = CartDiscountRule::active()
                ->where('min_amount', '<=', $subtotal)
                ->orderByDesc('min_amount')
                ->first();
            $cartDiscountAmount = $cartDiscountRule ? $cartDiscountRule->calculateDiscount($subtotal) : 0;

            // اگه مشتری یه گزینه‌ی حمل مشخص انتخاب کرده، هزینه‌ی همون گزینه
            // استفاده می‌شه؛ وگرنه (فقط آدرس داده شده بدون انتخاب شرکت خاص)
            // از نرخ ساده‌ی داخلی استفاده می‌کنیم؛ اگه هیچ‌کدوم نباشه صفره.
            $shippingCost = match (true) {
                $selectedShippingOption !== null => $selectedShippingOption['cost'],
                $shippingAddress !== null => $this->shippingCalculator->calculate($totalWeightKg, $shippingAddress->city),
                default => 0,
            };

            // ⚠️ همیشه رند می‌کنیم (نه فقط وقتی کد تخفیف بود) - هم برای
            // یکدستی، هم چون بند ۳.۳ سند صریحاً «رند کردن قیمت پس از اعمال
            // تخفیف» رو خواسته.
            $totalAmount = PriceCalculator::round($subtotal - $discountAmount - $cartDiscountAmount + $shippingCost);

            $order = Order::create([
                'user_id' => $request->user()->id,
                'coupon_id' => $coupon?->id,
                'referral_code_id' => $referralCode?->id,
                'shipping_address_id' => $shippingAddress?->id,
                'status' => Order::STATUS_PENDING_REVIEW,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'cart_discount_amount' => $cartDiscountAmount,
                'shipping_cost' => $shippingCost,
                'shipping_carrier' => $selectedShippingOption['carrier'] ?? null,
                'shipping_service_name' => $selectedShippingOption['service_name'] ?? null,
                'total_amount' => $totalAmount,
                'customer_note' => $request->input('customer_note'),
                'shipping_receiver_name' => $shippingAddress?->receiver_name,
                'shipping_receiver_phone' => $shippingAddress?->receiver_phone,
                'shipping_city' => $shippingAddress?->city,
                'shipping_full_address' => $shippingAddress?->full_address,
                'shipping_postal_code' => $shippingAddress?->postal_code,
                'shipping_latitude' => $shippingAddress?->latitude,
                'shipping_longitude' => $shippingAddress?->longitude,
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            if ($coupon) {
                $order->couponUsage()->create([
                    'coupon_id' => $coupon->id,
                    'user_id' => $request->user()->id,
                    'discount_amount' => $discountAmount,
                ]);

                $coupon->increment('used_count');
            }

            if ($referralCode) {
                // مبلغ پورسانت هنوز مشخص نیست (null) چون سفارش هنوز پرداخت
                // نشده؛ فقط وقتی سفارش paid بشه (بند ۵: «معرفی‌های موفق»)
                // توی PaymentController محاسبه و status به approved می‌ره.
                $order->referralCommission()->create([
                    'referral_code_id' => $referralCode->id,
                    'user_id' => $referralCode->user_id,
                    'commission_amount' => null,
                    'status' => ReferralCommission::STATUS_PENDING,
                ]);
            }

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => Order::STATUS_PENDING_REVIEW,
                'changed_by' => null,
                'note' => 'ثبت اولیه‌ی سفارش توسط مشتری.',
            ]);

            return $order;
        });

        // پیامک اطلاع‌رسانی به مشتری که سفارش در حال بررسیه (بند ۴ سند)
        // نام الگو دقیقاً باید با چیزی که توی پنل کاوه‌نگار ساختی یکی باشه.

        // if ($order->user->phone) {
        //     $this->sms->sendByTemplate($order->user->phone, 'order-registered', [
        //         (string) $order->id,
        //     ]);
        // }
        if ($order->shipping_receiver_phone) {
            $this->sms->sendByTemplate(
                $order->shipping_receiver_phone,
                'order-registered',
                [(string) $order->id]
            );
        }

        $this->notifyStaff("سفارش جدید #{$order->id} ثبت شد و در انتظار بررسی موجودیه.");

        return response()->json([
            'message' => 'سفارش شما ثبت شد و در حال بررسی موجودی است.',
            'order' => $order->load('items'),
        ], 201);
    }

    /**
     * تایید سبد اصلاح‌شده توسط مشتری، بعد از این‌که ادمین به‌خاطر نبود موجودی
     * بخشی از سفارش رو ویرایش کرده (بند ۴ سند، حالت دوم).
     * بعد از تایید، وضعیت به awaiting_payment می‌ره تا مشتری بتونه از طریق
     * /orders/{id}/pay لینک پرداخت واقعی زرین‌پال رو بگیره.
     */
    public function confirm(Request $request, Order $order)
    {
        Log::info('confirm entered');
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این سفارش متعلق به شما نیست.'], 403);
        }

        if ($order->status !== Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION) {
            return response()->json(['message' => 'این سفارش در وضعیتی نیست که نیاز به تایید شما داشته باشد.'], 422);
        }

        $order->update(['confirmed_by_customer_at' => now()]);
        $order->transitionTo(Order::STATUS_AWAITING_PAYMENT, null, 'مشتری سبد اصلاح‌شده را تایید کرد.');

        // if ($order->user->phone) {
        //     $this->sms->sendByTemplate($order->user->phone, 'order-approved', [
        //         (string) $order->id,
        //     ]);
        // }

        if ($order->shipping_receiver_phone) {
            $this->sms->sendByTemplate(
                $order->shipping_receiver_phone,
                'order-approved',
                [(string) $order->id]
            );
        }

        return response()->json([
            'message' => 'سفارش شما تایید شد و به مرحله‌ی پرداخت می‌رود.',
            'order' => $order->fresh('items'),
        ]);
    }

    /**
     * لغو سفارش توسط خودِ مشتری - فقط تا وقتی هنوز پرداخت نشده.
     */
    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'این سفارش متعلق به شما نیست.'], 403);
        }

        if (! $order->isOpen()) {
            return response()->json(['message' => 'این سفارش دیگر قابل لغو نیست.'], 422);
        }

        $order->update(['cancelled_at' => now()]);
        $order->transitionTo(Order::STATUS_CANCELLED, null, 'سفارش توسط مشتری لغو شد.');

        $order->referralCommission?->update(['status' => ReferralCommission::STATUS_CANCELLED]);

        return response()->json(['message' => 'سفارش لغو شد.']);
    }

    /**
     * اعتبارسنجی کامل یه کد تخفیف: وجود، فعال بودن، بازه‌ی زمانی، حداقل مبلغ
     * سبد، ظرفیت کلی، و ظرفیت هر کاربر. اگه هرکدوم رد بشه، پیام خطای مناسب
     * برمی‌گرده تا Controller مستقیم به کاربر نشون بده.
     *
     * @return array{coupon: ?Coupon, error: ?string}
     */
    private function resolveCoupon(string $code, int $subtotal, int $userId): array
    {
        $coupon = Coupon::active()->where('code', strtoupper(trim($code)))->first();

        if (! $coupon) {
            return ['coupon' => null, 'error' => 'کد تخفیف نامعتبر یا منقضی‌شده است.'];
        }

        if (! $coupon->meetsMinimumCartAmount($subtotal)) {
            return ['coupon' => null, 'error' => 'مبلغ سبد خرید برای استفاده از این کد تخفیف کافی نیست.'];
        }

        if (! $coupon->hasRemainingCapacity()) {
            return ['coupon' => null, 'error' => 'ظرفیت استفاده از این کد تخفیف تمام شده است.'];
        }

        if (! $coupon->isUsableByUser($userId)) {
            return ['coupon' => null, 'error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید.'];
        }

        return ['coupon' => $coupon, 'error' => null];
    }

    /**
     * اعلان داخلی به شماره‌ی(های) ادمین/انبار تنظیم‌شده توی .env (ADMIN_ALERT_MOBILE،
     * با کاما جدا برای چند شماره). از متد send() آزاد استفاده می‌شه چون این پیامک
     * برای کارمند داخلیه، نه مشتری نهایی.
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
