<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAlert extends Model
{
    protected $fillable = [
        'product_id',
        'average_quantity',
        'actual_quantity',
        'tolerance_percent',
        'period_days',
    ];

    protected function casts(): array
    {
        return [
            'average_quantity' => 'float',
            'actual_quantity' => 'integer',
            'tolerance_percent' => 'integer',
            'period_days' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}