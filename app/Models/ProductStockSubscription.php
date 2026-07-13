<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockSubscription extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'mobile',
        'notified',
    ];

    protected function casts(): array
    {
        return [
            'notified' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
