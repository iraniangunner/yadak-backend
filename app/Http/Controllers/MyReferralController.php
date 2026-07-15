<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use App\Models\ReferralCommission;
use Illuminate\Http\Request;

/**
 * برخلاف AdminReferralCode/CommissionController (که ادمین همه‌ی کدها رو
 * می‌بینه)، این کنترلر فقط اطلاعات خودِ کاربر لاگین‌شده (مثلاً یه فروشنده)
 * رو برمی‌گردونه - شبیه الگوی /orders خودِ مشتری.
 */
class MyReferralController extends Controller
{
    /**
     * کد(های) معرف خودِ کاربر لاگین‌شده.
     */
    public function code(Request $request)
    {
        $codes = ReferralCode::where('user_id', $request->user()->id)->get();

        return response()->json(['data' => $codes]);
    }

    /**
     * پورسانت‌های خودِ کاربر لاگین‌شده، با فیلتر اختیاری وضعیت.
     */
    public function commissions(Request $request)
    {
        $commissions = ReferralCommission::where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->with(['order:id,status,total_amount', 'referralCode:id,code'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($commissions);
    }
}
