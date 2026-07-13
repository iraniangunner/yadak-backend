<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Brand extends Model
{

    use HasFactory , LogsActivity;

    protected $fillable = ['name', 'slug', 'thumbnail', 'is_active', 'sales_stopped'];

    protected $appends = ['thumbnail_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sales_stopped' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * آدرس کامل قابل نمایش thumbnail، از طریق ImageService ساخته می‌شه
     * تا منطق ساخت URL یک‌جا (نه پخش‌شده توی هر مدل) باشه.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        return app(\App\Services\ImageService::class)->url($this->thumbnail);
    }
}