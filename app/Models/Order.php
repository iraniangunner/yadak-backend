<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use LogsActivity , HasFactory;

    /**
     * تغییرات فقط روی status لاگ نشه، چون توی order_status_histories
     * (که transitionTo() می‌سازه) با جزئیات بیشتر (from/to/changed_by/note)
     * ثبت می‌شه؛ لاگ کردنش اینجا هم فقط تکراریه.
     */
    protected array $activityLogExcludeFields = ['status'];

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_NEEDS_CUSTOMER_CONFIRMATION = 'needs_customer_confirmation';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /**
     * وضعیت‌هایی که هنوز «باز» هستن (برخلاف cancelled/expired/paid که نهایی‌ان).
     */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_NEEDS_CUSTOMER_CONFIRMATION,
        self::STATUS_AWAITING_PAYMENT,
    ];

    protected $fillable = [
        'user_id',
        'coupon_id',
        'referral_code_id',
        'shipping_address_id',
        'status',
        'subtotal',
        'discount_amount',
        'shipping_cost',
        'total_amount',
        'customer_note',
        'admin_note',
        'payment_authority',
        'payment_ref_id',
        'payment_link_expires_at',
        'confirmed_by_customer_at',
        'paid_at',
        'cancelled_at',
        'shipping_receiver_name',
        'shipping_receiver_phone',
        'shipping_city',
        'shipping_full_address',
        'shipping_postal_code',
        'shipping_latitude',
        'shipping_longitude',
        'shipping_carrier',
        'shipping_service_name',
        'invoice_number',
        'invoice_url',
        'invoiced_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'shipping_cost' => 'integer',
            'total_amount' => 'integer',
            'payment_link_expires_at' => 'datetime',
            'confirmed_by_customer_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class);
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function referralCommission(): HasOne
    {
        return $this->hasOne(ReferralCommission::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    /**
     * تغییر وضعیت سفارش + ثبت خودکار توی order_status_histories.
     * این تنها راهیه که Controller ها باید برای تغییر status استفاده کنن،
     * تا هیچ‌وقت یادمون نره لاگش کنیم (بند ۵ سند: ثبت لاگ تغییرات).
     *
     * @param  User|null  $changedBy  null یعنی تغییر خودکار/سیستمی بوده (مثلاً expire شدن)
     */
    public function transitionTo(string $newStatus, ?User $changedBy = null, ?string $note = null): void
    {
        $oldStatus = $this->status;

        $this->update(['status' => $newStatus]);

        $this->statusHistories()->create([
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'changed_by' => $changedBy?->id,
            'note' => $note,
        ]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}