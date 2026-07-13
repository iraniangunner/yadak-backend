<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class Category extends Model
{
    use HasFactory , LogsActivity;

    protected $fillable = ['parent_id', 'name', 'slug', 'thumbnail', 'is_active', 'sales_stopped', 'sort_order'];

    protected $appends = ['thumbnail_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sales_stopped' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return app(\App\Services\ImageService::class)->url($this->thumbnail);
    }
}