<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'خانه',
            'receiver_name' => $this->faker->name(),
            'receiver_phone' => '0912' . $this->faker->numberBetween(1000000, 9999999),
            'province' => 'تهران',
            'city' => 'تهران',
            'full_address' => $this->faker->address(),
            'postal_code' => '1234567890',
            'latitude' => null,
            'longitude' => null,
            'is_default' => false,
        ];
    }
}
