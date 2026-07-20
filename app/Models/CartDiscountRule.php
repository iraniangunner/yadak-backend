<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartDiscountRule extends Model
{
    use HasFactory;

    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FIXED = 'fixed';

    protected $fillable = ['min_amount', 'type', 'value', 'is_active'];

    protected $casts = [
        'min_amount' => 'integer',
        'value' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * مبلغ تخفیف رو برای یه subtotal مشخص حساب می‌کنه (بدون رند - رند نهایی
     * توی خودِ OrderController با PriceCalculator انجام می‌شه).
     */
    public function calculateDiscount(int $subtotal): int
    {
        if ($this->type === self::TYPE_PERCENTAGE) {
            return (int) round($subtotal * $this->value / 100);
        }

        return min($this->value, $subtotal);
    }
}
