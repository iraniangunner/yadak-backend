<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    use HasFactory;
    protected $fillable = [
        'city',
        'base_price',
        'price_per_kg',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'price_per_kg' => 'integer',
        ];
    }
}
