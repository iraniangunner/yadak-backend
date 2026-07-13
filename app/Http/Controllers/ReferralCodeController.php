<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReferralCodeController extends Controller
{
    public function index(Request $request)
    {
        $codes = ReferralCode::query()
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->integer('user_id')))
            ->with('user:id,name,phone,email')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($codes);
    }

    public function store(Request $request)
    {
        $validated = $this->validateReferralCode($request);

        $referralCode = ReferralCode::create($validated);

        return response()->json(['referral_code' => $referralCode->fresh('user')], 201);
    }

    public function update(Request $request, ReferralCode $referralCode)
    {
        $validated = $this->validateReferralCode($request, $referralCode);

        $referralCode->update($validated);

        return response()->json(['referral_code' => $referralCode->fresh('user')]);
    }

    public function destroy(ReferralCode $referralCode)
    {
        $referralCode->delete();

        return response()->json(['message' => 'کد معرف با موفقیت حذف شد.']);
    }

    private function validateReferralCode(Request $request, ?ReferralCode $referralCode = null): array
    {
        $sometimes = (bool) $referralCode;
        $rule = $sometimes ? 'sometimes' : 'required';

        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        $validator = Validator::make($request->all(), [
            'code' => [
                $rule,
                'string',
                'max:50',
                Rule::unique('referral_codes', 'code')->ignore($referralCode?->id),
            ],
            'user_id' => "{$rule}|exists:users,id",
            'commission_type' => "{$rule}|in:percentage,fixed",
            'commission_value' => [
                $rule,
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request, $referralCode) {
                    $type = $request->input('commission_type', $referralCode?->commission_type);
                    if ($type === ReferralCode::TYPE_PERCENTAGE && $value > 100) {
                        $fail('درصد پورسانت نمی‌تواند بیشتر از ۱۰۰ باشد.');
                    }
                },
            ],
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
