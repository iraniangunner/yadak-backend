<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'type' => Coupon::TYPE_PERCENTAGE,
            'value' => 10,
            'min_cart_amount' => null,
            'max_discount_amount' => null,
            'usage_limit' => null,
            'used_count' => 0,
            'usage_limit_per_user' => null,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
