<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'brand' => $this->faker->randomElement(['پژو', 'سایپا', 'ایران خودرو']),
            'model' => $this->faker->randomElement(['206', 'پارس', 'تیبا', 'سمند']),
            'generation' => null,
            'year_from' => 1390,
            'year_to' => 1400,
            'is_active' => true,
        ];
    }
}
