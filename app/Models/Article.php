<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Article extends Model
{
    use LogsActivity , HasFactory;

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'thumbnail',
        'excerpt',
        'content',
        'is_published',
        'published_at',
    ];

    protected $appends = ['thumbnail_url'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * محصولات پیشنهادی که زیر مقاله نمایش داده می‌شن (بند ۶ سند).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return app(\App\Services\ImageService::class)->url($this->thumbnail);
    }

    /**
     * آیا این مقاله الان واقعاً برای عموم قابل مشاهده‌ست؟
     * (هم پرچم is_published روشن باشه، هم زمان انتشارش رسیده باشه).
     */
    public function isVisible(): bool
    {
        return $this->is_published && (! $this->published_at || $this->published_at->isPast());
    }
}