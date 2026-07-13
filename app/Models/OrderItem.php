<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{

    use HasFactory;
    
    protected $fillable = [
        'order_id',
        'product_id',
        'title',
        'sku',
        'price',
        'quantity',
        'is_available',
        'removed_by_admin',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'integer',
            'is_available' => 'boolean',
            'removed_by_admin' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * محصول واقعی (ممکنه null باشه اگه محصول بعداً حذف شده باشه؛
     * برای همین همیشه از title/sku/price ذخیره‌شده روی خودِ آیتم استفاده کن،
     * نه از رابطه‌ی product، چون قیمت/عنوان محصول ممکنه از اون موقع عوض شده باشه).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getSubtotalAttribute(): int
    {
        return $this->price * $this->quantity;
    }

     public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }
}
