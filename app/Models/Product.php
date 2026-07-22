<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Services\PriceCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class Product extends Model
{

    use HasFactory, LogsActivity;
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';
    public const STATUS_INCOMING = 'incoming';

    /**
     * کش موقت نتیجه‌ی resolveActiveDiscount روی همین instance، تا وقتی هم
     * final_price هم discount_percent صدا زده می‌شن، دوبار کوئری نزنه.
     */
    private bool $activeDiscountResolved = false;
    private ?Discount $cachedActiveDiscount = null;

    protected $fillable = [
        'category_id',
        'brand_id',
        'vehicle_brand',
        'vehicle_model',
        'vehicle_type',
        'title',
        'slug',
        'sku',
        'thumbnail',
        'description',
        'price',
        'compare_price',
        'stock_status',
        'weight_kg',
        'dimensions',
        'package_type',
        'is_active',
    ];

    protected $hidden = [
        // مشخصات فیزیکی فقط برای ادمین/انبار قابل مشاهده‌ست؛
        // به‌صورت پیش‌فرض از خروجی JSON عمومی (برای مشتری) مخفی می‌کنیم.
        // توی Controller/Resource مربوط به ادمین/انبار با makeVisible() نمایششون بده.
        'weight_kg',
        'dimensions',
        'package_type',
    ];

    protected $appends = [
        'thumbnail_url',
        'final_price',
        'discount_percent',
        'is_purchasable',
        'average_rating',
        'reviews_count',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_price' => 'integer',
            'weight_kg' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // public function vehicles(): BelongsToMany
    // {
    //     return $this->belongsToMany(Vehicle::class, 'product_vehicle');
    // }

    public function stockSubscriptions(): HasMany
    {
        return $this->hasMany(ProductStockSubscription::class);
    }

    /**
     * عکس‌های گالری محصول (علاوه بر thumbnail که عکس اصلی/کاور محصوله).
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return app(\App\Services\ImageService::class)->url($this->thumbnail);
    }

    public function isAvailable(): bool
    {
        return $this->stock_status === self::STATUS_AVAILABLE;
    }

    /**
     * آیا این محصول واقعاً قابل خریده؟ برخلاف isAvailable() که فقط
     * stock_status خودِ محصول رو چک می‌کنه، این متد توقف فروش دسته‌ای
     * (بند ۵ سند: «توقف فروش موردی یا دسته‌ای») روی دسته‌بندی و برند
     * محصول رو هم در نظر می‌گیره.
     */
    public function isPurchasable(): bool
    {
        if (! $this->is_active || $this->stock_status !== self::STATUS_AVAILABLE) {
            return false;
        }

        if ($this->category && $this->category->sales_stopped) {
            return false;
        }

        if ($this->brand && $this->brand->sales_stopped) {
            return false;
        }

        return true;
    }

    public function getIsPurchasableAttribute(): bool
    {
        return $this->isPurchasable();
    }

    /**
     * تخفیف‌های ثبت‌شده مستقیم روی خودِ این محصول (نه دسته/برندش).
     */
    public function discounts(): MorphMany
    {
        return $this->morphMany(Discount::class, 'discountable');
    }

    /**
     * تخفیف فعالی که باید روی این محصول اعمال بشه، با این اولویت:
     * ۱. تخفیف مستقیم روی خودِ محصول
     * ۲. تخفیف روی دسته‌بندیِ محصول
     * ۳. تخفیف روی برندِ محصول
     * (یعنی تخفیف‌ها با هم جمع/ترکیب نمی‌شن؛ خاص‌ترین سطح برنده‌ست.)
     */
    public function resolveActiveDiscount(): ?Discount
    {
        if ($this->activeDiscountResolved) {
            return $this->cachedActiveDiscount;
        }

        $this->activeDiscountResolved = true;

        $productDiscount = $this->discounts()->active()->latest()->first();
        if ($productDiscount) {
            return $this->cachedActiveDiscount = $productDiscount;
        }

        if ($this->category_id) {
            $categoryDiscount = Discount::query()
                ->active()
                ->where('discountable_type', (new Category)->getMorphClass())
                ->where('discountable_id', $this->category_id)
                ->latest()
                ->first();

            if ($categoryDiscount) {
                return $this->cachedActiveDiscount = $categoryDiscount;
            }
        }

        if ($this->brand_id) {
            $brandDiscount = Discount::query()
                ->active()
                ->where('discountable_type', (new Brand)->getMorphClass())
                ->where('discountable_id', $this->brand_id)
                ->latest()
                ->first();

            if ($brandDiscount) {
                return $this->cachedActiveDiscount = $brandDiscount;
            }
        }

        return $this->cachedActiveDiscount = null;
    }

    /**
     * قیمت نهایی بعد از اعمال تخفیف (اگه باشه) + رُند کردن.
     * اگه تخفیفی وجود نداشته باشه، همون price خام برمی‌گرده.
     */
    public function getFinalPriceAttribute(): ?int
    {
        if ($this->price === null) {
            return null;
        }

        return $this->applyDiscount($this->price);
    }
    /**
     * اعمال تخفیف فعال (اگه باشه) روی یه قیمت پایه‌ی دلخواه + رُند کردن.
     * این متد جداست تا هم برای قیمت عادی محصول (final_price) هم برای
     * قیمت پلکانی (priceForQuantity) قابل استفاده‌ی مجدد باشه، بدون
     * تکرار منطق محاسبه‌ی تخفیف.
     */
    private function applyDiscount(int $basePrice): int
    {
        $discount = $this->resolveActiveDiscount();

        if (! $discount) {
            return $basePrice;
        }

        $discounted = $discount->type === Discount::TYPE_PERCENTAGE
            ? (int) round($basePrice * (100 - $discount->value) / 100)
            : max(0, $basePrice - $discount->value);

        return PriceCalculator::round($discounted);
    }

    /**
     * درصد تخفیف قابل نمایش (برای بج "٪۲۰ تخفیف" توی فرانت). اگه تخفیف از
     * نوع fixed باشه، درصدش رو از روی قیمت اصلی محاسبه می‌کنیم.
     */
    public function getDiscountPercentAttribute(): int
    {
        if ($this->price <= 0) {
            return 0;
        }

        $discount = $this->resolveActiveDiscount();

        if (! $discount) {
            return 0;
        }

        if ($discount->type === Discount::TYPE_PERCENTAGE) {
            return min(100, $discount->value);
        }

        return (int) round(($discount->value / $this->price) * 100);
    }

    /**
     * ردیف‌های قیمت پلکانی این محصول، مرتب‌شده بر اساس min_quantity.
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity');
    }

    /**
     * قیمت واحد برای یه تعداد مشخص، با در نظر گرفتن قیمت پلکانی (اگه تعریف
     * شده باشه) + تخفیف فعال روی همون قیمت پایه.
     *
     * اگه هیچ tier ای برای این تعداد تعریف نشده باشه (یا اصلاً tier نداشته
     * باشه)، قیمت عادی محصول (price) به‌عنوان قیمت پایه در نظر گرفته می‌شه.
     */
    public function priceForQuantity(int $quantity): int
    {
        $tier = $this->priceTiers
            ->first(fn(ProductPriceTier $tier) => $tier->coversQuantity($quantity));

        $basePrice = $tier?->price ?? $this->price;

        return $this->applyDiscount($basePrice);
    }



    /*
|--------------------------------------------------------------------------
| این متدها رو به مدل Product.php اضافه کن
|--------------------------------------------------------------------------
| نکته: رابطه‌ی ویژگی‌ها رو عمداً productAttributes می‌نامیم (نه attributes)
| تا با متدهای داخلی خودِ Eloquent قاطی نشه.
*/

    public function productAttributes()
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('sort_order');
    }

    public function favorites()
    {
        return $this->hasMany(ProductFavorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class)->where('is_approved', true)->latest();
    }

    public function orderItems()
    {
        return $this->hasMany(\App\Models\OrderItem::class);
    }

    /**
     * میانگین امتیاز (فقط نظرات تأییدشده)، گرد شده به یک رقم اعشار.
     * این دو رو به $appends مدل هم اضافه کن: 'average_rating', 'reviews_count'
     */
    public function getAverageRatingAttribute(): ?float
    {
        $avg = $this->reviews()->avg('rating');

        return $avg ? round($avg, 1) : null;
    }

    public function getReviewsCountAttribute(): int
    {
        return $this->reviews()->count();
    }

    public function complementaryProducts(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_complementary',
            'product_id',
            'complementary_product_id'
        );
    }
}
