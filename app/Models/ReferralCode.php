<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    use HasFactory;
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'code',
        'user_id',
        'commission_type',
        'commission_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'commission_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * کد همیشه با حروف بزرگ و بدون فاصله‌ی اضافه ذخیره می‌شه (مثل Coupon)،
     * تا مطابقت کد وارد‌شده توسط مشتری مشکلی نداشته باشه.
     */
    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    /**
     * صاحب کد (معرف/فروشنده‌ای که پورسانت می‌گیره).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * محاسبه‌ی مبلغ پورسانت روی یه مبلغ سفارش مشخص (معمولاً total_amount
     * نهایی پرداخت‌شده، نه subtotal خام).
     */
    public function calculateCommission(int $orderAmount): int
    {
        return $this->commission_type === self::TYPE_PERCENTAGE
            ? (int) round($orderAmount * $this->commission_value / 100)
            : min($this->commission_value, $orderAmount);
    }
}
