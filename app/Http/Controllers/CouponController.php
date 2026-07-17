<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $coupons = Coupon::query()
            ->when($request->filled('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($coupons);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCoupon($request);

        $coupon = Coupon::create($validated);

        return response()->json(['coupon' => $coupon], 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $validated = $this->validateCoupon($request, $coupon);

        $coupon->update($validated);

        return response()->json(['coupon' => $coupon->fresh()]);
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json(['message' => 'کد تخفیف با موفقیت حذف شد.']);
    }


    /**
     * بررسی زنده‌ی یه کد تخفیف قبل از ثبت نهایی سفارش (توی صفحه‌ی
     * تسویه‌حساب مشتری). این فقط برای پیش‌نمایشه - اعتبارسنجی نهایی و
     * واقعی همچنان توی OrderController::store() انجام می‌شه.
     */
    public function check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'subtotal' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['valid' => false, 'message' => 'اطلاعات ارسالی ناقصه.'], 422);
        }

        $code = strtoupper(trim($request->string('code')->toString()));
        $subtotal = $request->integer('subtotal');

        $coupon = Coupon::active()->where('code', $code)->first();

        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'کد تخفیف پیدا نشد یا منقضی شده.']);
        }

        if (! $coupon->hasRemainingCapacity()) {
            return response()->json(['valid' => false, 'message' => 'ظرفیت استفاده از این کد تمام شده.']);
        }

        if (! $coupon->isUsableByUser($request->user()->id)) {
            return response()->json(['valid' => false, 'message' => 'شما قبلاً از این کد استفاده کرده‌اید.']);
        }

        if (! $coupon->meetsMinimumCartAmount($subtotal)) {
            return response()->json([
                'valid' => false,
                'message' => 'مبلغ سبد خرید شما به حداقل لازم برای این کد نرسیده (' .
                    number_format($coupon->min_cart_amount) . ' تومان).',
            ]);
        }

        return response()->json([
            'valid' => true,
            'discount_amount' => $coupon->calculateDiscount($subtotal),
            'message' => 'کد تخفیف معتبره.',
        ]);
    }

    private function validateCoupon(Request $request, ?Coupon $coupon = null): array
    {
        $sometimes = (bool) $coupon;
        $rule = $sometimes ? 'sometimes' : 'required';

        // نرمال‌سازی کد قبل از اعتبارسنجی unique، تا مستقل از collation
        // دیتابیس (MySQL vs SQLite)، همیشه یکسان مقایسه بشه.
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        $validator = Validator::make($request->all(), [
            'code' => [
                $rule,
                'string',
                'max:50',
                Rule::unique('coupons', 'code')->ignore($coupon?->id),
            ],
            'type' => "{$rule}|in:percentage,fixed",
            'value' => [
                $rule,
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request, $coupon) {
                    $type = $request->input('type', $coupon?->type);
                    if ($type === Coupon::TYPE_PERCENTAGE && $value > 100) {
                        $fail('درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.');
                    }
                },
            ],
            'min_cart_amount' => 'nullable|integer|min:0',
            'max_discount_amount' => 'nullable|integer|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        $validated = $validator->validated();

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
        }

        return $validated;
    }
}
