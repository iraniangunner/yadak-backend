<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'image' => 'banners/placeholder.jpg',
            'product_id' => null,
            'link_url' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
