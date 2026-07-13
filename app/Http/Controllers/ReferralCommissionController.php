<?php

namespace App\Http\Controllers;

use App\Models\ReferralCommission;
use Illuminate\Http\Request;

class ReferralCommissionController extends Controller
{
    /**
     * گزارش پورسانت‌ها، قابل فیلتر بر اساس معرف (user_id) و وضعیت.
     * برای گزارش‌گیری مدیریتی (بند ۵ سند: «محاسبه پورسانت قابل گزارش»).
     */
    public function index(Request $request)
    {
        $commissions = ReferralCommission::query()
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->with(['user:id,name,phone', 'order:id,status,total_amount', 'referralCode:id,code'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($commissions);
    }

    /**
     * ثبت پرداخت واقعی پورسانت به معرف (بعد از واریز دستی خارج از سیستم).
     * فقط پورسانت‌هایی که approved هستن (یعنی سفارششون پرداخت شده) قابل
     * علامت‌گذاری به paid هستن.
     */
    public function markPaid(ReferralCommission $commission)
    {
        if ($commission->status !== ReferralCommission::STATUS_APPROVED) {
            return response()->json([
                'message' => 'فقط پورسانت‌های تاییدشده (سفارش پرداخت‌شده) قابل ثبت پرداخت هستند.',
            ], 422);
        }

        $commission->update(['status' => ReferralCommission::STATUS_PAID]);

        return response()->json(['commission' => $commission->fresh()]);
    }
}
