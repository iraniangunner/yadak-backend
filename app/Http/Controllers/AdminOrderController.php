<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ReferralCommission;
use App\Services\PriceCalculator;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminOrderController extends Controller
{
    public function __construct(private SmsService $sms)
    {
    }

    /**
     * لیست همه‌ی سفارش‌ها با فیلتر بر اساس وضعیت (بند ۴ سند: گزارش‌گیری از
     * سفارش‌های رهاشده/پرداخت‌نشده/تأییدنشده).
     */
    public function index(Request $request)
    {
        $orders = Order::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['user:id,name,phone,email'])
            ->withCount('items')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($orders);
    }

    /**
     * نمایش کامل یک سفارش شامل آیتم‌ها، تاریخچه‌ی وضعیت و اطلاعات مشتری.
     */
    public function show(Order $order)
    {
        $order->load(['items', 'statusHistories.changedBy:id,name', 'user:id,name,phone,email']);

        return response()->json(['order' => $order]);
    }

    /**
     * تایید کامل سفارش وقتی همه‌ی اقلام موجوده (بند ۴ سند، حالت اول).
     * مستقیم به awaiting_payment می‌ره، بدون نیاز به تایید مجدد مشتری.
     */
    public function approve(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PENDING_REVIEW) {
            return response()->json(['message' => 'این سفارش در وضعیتی نیست که قابل تایید باشد.'], 422);
        }

        $order->transitionTo(
            Order::STATUS_AWAITING_PAYMENT,
            $request->user(),
            'سفارش توسط ادمین تایید شد؛ همه‌ی اقلام موجود بودند.'
        );

        // پیامک راهنمای پرداخت به مشتری (بند ۴ سند)
        // نام الگو دقیقاً باید با چیزی که توی پنل کاوه‌نگار ساختی یکی باشه.
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
            'message' => 'سفارش تایید شد و به مرحله‌ی پرداخت می‌رود.',
            'order' => $order->fresh('items'),
        ]);
    }

    /**
     * ویرایش آیتم‌های سفارش وقتی بخشی از کالاها موجود نیست (بند ۴ سند، حالت دوم).
     * بعد از این عملیات، سفارش به needs_customer_confirmation می‌ره تا مشتری
     * سبد اصلاح‌شده رو تایید کنه.
     *
     * ورودی: items: [{id: order_item_id, quantity, is_available}], admin_note (اختیاری)
     */
    public function updateItems(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PENDING_REVIEW) {
            return response()->json(['message' => 'این سفارش در وضعیتی نیست که قابل ویرایش باشد.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.is_available' => 'required|boolean',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orderItemIds = $order->items()->pluck('id');
        $invalidIds = collect($request->input('items'))->pluck('id')->diff($orderItemIds);

        if ($invalidIds->isNotEmpty()) {
            return response()->json(['message' => 'برخی از آیتم‌های ارسالی متعلق به این سفارش نیستند.'], 422);
        }

        DB::transaction(function () use ($request, $order) {
            foreach ($request->input('items') as $itemInput) {
                $order->items()->where('id', $itemInput['id'])->update([
                    'quantity' => $itemInput['quantity'],
                    'is_available' => $itemInput['is_available'],
                    'removed_by_admin' => ! $itemInput['is_available'],
                ]);
            }

            // جمع مبلغ فقط بر اساس آیتم‌هایی که موجودن محاسبه می‌شه
            $subtotal = $order->items()->where('is_available', true)
                ->get()
                ->sum(fn ($item) => $item->price * $item->quantity);

            // اگه سفارش کد تخفیف داشته، تخفیف رو متناسب با subtotal جدید
            // دوباره حساب می‌کنیم (برای درصدی طبیعتاً کمتر می‌شه؛ برای مبلغ
            // ثابت، سقفش subtotal جدیده تا منفی نشه).
            $discountAmount = 0;
            if ($order->coupon_id && $order->coupon) {
                $discountAmount = $order->coupon->calculateDiscount($subtotal);
                $order->couponUsage()->update(['discount_amount' => $discountAmount]);
            }

            $order->update([
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total_amount' => PriceCalculator::round($subtotal - $discountAmount + $order->shipping_cost),
                'admin_note' => $request->input('admin_note', $order->admin_note),
            ]);

            $order->transitionTo(
                Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION,
                $request->user(),
                'ادمین سفارش را به‌خاطر نبود موجودی ویرایش کرد.'
            );
        });

        // پیامک لینک تایید سبد اصلاح‌شده به مشتری (بند ۴ سند)
        $order->refresh();
        // if ($order->user->phone) {
        //     $this->sms->sendByTemplate($order->user->phone, 'order-needs-confirmation', [
        //         (string) $order->id,
        //     ]);
        // }
        
        if ($order->shipping_receiver_phone) {
            $this->sms->sendByTemplate(
                $order->shipping_receiver_phone,
                'order-needs-confirmation',
                [(string) $order->id]
            );
        }

        return response()->json([
            'message' => 'سفارش ویرایش شد و برای تایید مشتری ارسال می‌شود.',
            'order' => $order->fresh('items'),
        ]);
    }

    /**
     * لغو سفارش توسط ادمین (مثلاً وقتی هیچ‌کدوم از اقلام موجود نیست).
     */
    public function cancel(Request $request, Order $order)
    {
        if (! $order->isOpen()) {
            return response()->json(['message' => 'این سفارش دیگر قابل لغو نیست.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'note' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $order->update(['cancelled_at' => now()]);
        $order->transitionTo(Order::STATUS_CANCELLED, $request->user(), $request->input('note'));

        $order->referralCommission?->update(['status' => ReferralCommission::STATUS_CANCELLED]);

        return response()->json(['message' => 'سفارش لغو شد.']);
    }
}