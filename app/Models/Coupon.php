<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{

    use HasFactory;
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_cart_amount',
        'max_discount_amount',
        'usage_limit',
        'used_count',
        'usage_limit_per_user',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_cart_amount' => 'integer',
            'max_discount_amount' => 'integer',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'usage_limit_per_user' => 'integer',
            'starts_at' => 'date:Y-m-d',
            'ends_at' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * کد همیشه با حروف بزرگ و بدون فاصله‌ی اضافه ذخیره می‌شه، تا مطابقت
     * کد وارد‌شده توسط مشتری (که ممکنه حروف کوچیک بنویسه) مشکلی نداشته باشه.
     */
    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = today();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($today) {
                $q->whereNull('starts_at')
                    ->orWhereDate('starts_at', '<=', $today);
            })
            ->where(function (Builder $q) use ($today) {
                $q->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $today);
            });
    }

    /**
     * آیا ظرفیت کلی این کد (usage_limit) هنوز تموم نشده؟
     */
    public function hasRemainingCapacity(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    /**
     * آیا این کاربر خاص هنوز مجاز به استفاده از این کده (بر اساس usage_limit_per_user)؟
     */
    public function isUsableByUser(int $userId): bool
    {
        if ($this->usage_limit_per_user === null) {
            return true;
        }

        $usedByUser = $this->usages()->where('user_id', $userId)->count();

        return $usedByUser < $this->usage_limit_per_user;
    }

    /**
     * آیا مبلغ سبد خرید به حداقل لازم برای این کد رسیده؟
     */
    public function meetsMinimumCartAmount(int $subtotal): bool
    {
        return $this->min_cart_amount === null || $subtotal >= $this->min_cart_amount;
    }

    /**
     * محاسبه‌ی مبلغ تخفیف روی یه subtotal مشخص. هیچ‌وقت بیشتر از خودِ
     * subtotal نمی‌شه (تا total منفی نشه) و اگه max_discount_amount تعریف
     * شده باشه، سقفش رو رعایت می‌کنه.
     */
    public function calculateDiscount(int $subtotal): int
    {
        $discount = $this->type === self::TYPE_PERCENTAGE
            ? (int) round($subtotal * $this->value / 100)
            : $this->value;

        if ($this->max_discount_amount !== null) {
            $discount = min($discount, $this->max_discount_amount);
        }

        return min($discount, $subtotal);
    }
}
