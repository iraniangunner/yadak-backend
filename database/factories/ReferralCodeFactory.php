<?php

namespace Database\Factories;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReferralCodeFactory extends Factory
{
    protected $model = ReferralCode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'user_id' => User::factory(),
            'commission_type' => ReferralCode::TYPE_PERCENTAGE,
            'commission_value' => 10,
            'is_active' => true,
        ];
    }
}
