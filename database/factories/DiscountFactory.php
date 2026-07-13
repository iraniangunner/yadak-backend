<?php

namespace Database\Factories;

use App\Models\Discount;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'discountable_type' => 'product',
            'discountable_id' => Product::factory(),
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 20,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
