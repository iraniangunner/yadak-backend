<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'sort_order',
    ];

    protected $appends = ['url'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * آدرس کامل قابل نمایش عکس، از طریق ImageService (نه Storage مستقیم)
     * تا منطق ساخت URL یک‌جا مدیریت بشه.
     */
    public function getUrlAttribute(): ?string
    {
        return app(\App\Services\ImageService::class)->url($this->path);
    }
}
