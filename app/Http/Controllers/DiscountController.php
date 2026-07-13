<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    /**
     * نگاشت نوع ورودی (که از فرانت میاد) به اسم جدول واقعی، برای چک exists.
     * از همون alias هایی استفاده می‌کنه که توی AppServiceProvider::morphMap
     * تعریف شدن (product/category/brand) - نه FQCN کامل.
     */
    private const TYPE_TABLE_MAP = [
        'product' => 'products',
        'category' => 'categories',
        'brand' => 'brands',
    ];

    /**
     * لیست تخفیف‌ها، قابل فیلتر بر اساس نوع (product/category/brand).
     */
    public function index(Request $request)
    {
        $discounts = Discount::query()
            ->when($request->filled('discountable_type'), fn($q) => $q->where('discountable_type', $request->string('discountable_type')))
            ->with('discountable')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($discounts);
    }

    public function store(Request $request)
    {
        $validated = $this->validateDiscount($request);

        $discount = Discount::create($validated);

        return response()->json(['discount' => $discount->fresh('discountable')], 201);
    }

    public function update(Request $request, Discount $discount)
    {
        $validated = $this->validateDiscount($request, $discount);

        $discount->update($validated);

        return response()->json(['discount' => $discount->fresh('discountable')]);
    }

    public function destroy(Discount $discount)
    {
        $discount->delete();

        return response()->json(['message' => 'تخفیف با موفقیت حذف شد.']);
    }

    private function validateDiscount(Request $request, ?Discount $discount = null): array
    {
        $sometimes = (bool) $discount;
        $rule = $sometimes ? 'sometimes' : 'required';

        $type = $request->input('discountable_type', $discount?->discountable_type);
        $table = self::TYPE_TABLE_MAP[$type] ?? 'products';

        $validator = Validator::make($request->all(), [
            'discountable_type' => "{$rule}|in:product,category,brand",
            'discountable_id' => [$rule, 'integer', Rule::exists($table, 'id')],
            'type' => "{$rule}|in:percentage,fixed",
            'value' => [
                $rule,
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request, $discount) {
                    $discountType = $request->input('type', $discount?->type);
                    if ($discountType === Discount::TYPE_PERCENTAGE && $value > 100) {
                        $fail('درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.');
                    }
                },
            ],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
