<?php

namespace App\Http\Controllers;

use App\Models\CartDiscountRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CartDiscountRuleController extends Controller
{
    public function index(Request $request)
    {
        $rules = CartDiscountRule::query()
            ->orderBy('min_amount')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rules);
    }

    /**
     * لیست عمومی قوانینِ فعال - برای پیش‌نمایش توی صفحه‌ی تسویه‌حساب،
     * قبل از ثبت نهایی سفارش.
     */
    public function activeRules()
    {
        $rules = CartDiscountRule::active()->orderBy('min_amount')->get(['id', 'min_amount', 'type', 'value']);

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateRule($request);

        $rule = CartDiscountRule::create($validated);

        return response()->json(['rule' => $rule], 201);
    }

    public function update(Request $request, CartDiscountRule $cartDiscountRule)
    {
        $validated = $this->validateRule($request, sometimes: true);

        $cartDiscountRule->update($validated);

        return response()->json(['rule' => $cartDiscountRule->fresh()]);
    }

    public function destroy(CartDiscountRule $cartDiscountRule)
    {
        $cartDiscountRule->delete();

        return response()->json(['message' => 'قانون تخفیف حذف شد.']);
    }

    private function validateRule(Request $request, bool $sometimes = false): array
    {
        $rule = $sometimes ? 'sometimes' : 'required';

        $validator = Validator::make($request->all(), [
            'min_amount' => "{$rule}|integer|min:1",
            'type' => "{$rule}|in:percentage,fixed",
            'value' => [
                $rule,
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('type');
                    if ($type === 'percentage' && $value > 100) {
                        $fail('درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.');
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
