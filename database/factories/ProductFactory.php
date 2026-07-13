<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->words(3, true);

        return [
            'category_id' => null,
            'brand_id' => null,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(4),
            'sku' => strtoupper(Str::random(8)),
            'thumbnail' => null,
            'description' => $this->faker->sentence(),
            'price' => $this->faker->numberBetween(10000, 500000),
            'compare_price' => null,
            'stock_status' => Product::STATUS_AVAILABLE,
            'weight_kg' => null,
            'dimensions' => null,
            'package_type' => null,
            'is_active' => true,
        ];
    }
}
