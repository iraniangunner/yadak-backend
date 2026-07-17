<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use App\Models\ReferralCommission;
use Illuminate\Support\Facades\Validator;
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

    /**
     * بررسی زنده‌ی یه کد معرف قبل از ثبت نهایی سفارش (توی تسویه‌حساب
     * مشتری). برخلاف کد تخفیف، این کد هیچ اثری روی قیمت مشتری نداره -
     * فقط برای محاسبه‌ی پورسانتِ معرف/فروشنده بعد از پرداخت موفق استفاده
     * می‌شه؛ برای همین این پاسخ فقط valid/invalid برمی‌گردونه، نه تخفیف.
     */
    public function check(Request $request)
    {
        $validator = Validator::make($request->all(), ['code' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'message' => 'کد وارد نشده.'], 422);
        }

        $code = strtoupper(trim($request->string('code')->toString()));
        $referralCode = ReferralCode::active()->where('code', $code)->first();

        if (! $referralCode) {
            return response()->json(['valid' => false, 'message' => 'کد معرف پیدا نشد یا غیرفعاله.']);
        }

        if ($referralCode->user_id === $request->user()->id) {
            return response()->json(['valid' => false, 'message' => 'نمی‌تونید از کد معرف خودتون استفاده کنید.']);
        }

        return response()->json([
            'valid' => true,
            'message' => 'کد معرف معتبره (این کد تخفیفی نداره، فقط پورسانت به معرف تعلق می‌گیره).',
        ]);
    }
}
