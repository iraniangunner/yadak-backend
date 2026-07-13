<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * نقش‌ها بر اساس بخش «۵. پنل مدیریت» سند درخواست پیشنهاد:
     * «پنل مدیریت قابل استفاده برای مدیر، انبار، فروش، پشتیبانی و سایر نقش‌ها
     * با سطوح دسترسی متفاوت»
     */
    public const ROLE_CUSTOMER = 'customer';       // مشتری - نقش پیش‌فرض، ورود با OTP
    public const ROLE_ADMIN = 'admin';             // مدیر - دسترسی کامل به همه‌ی پنل
    public const ROLE_WAREHOUSE = 'warehouse';     // انبار - بررسی موجودی، تأیید/ویرایش سفارش پیش از پرداخت
    public const ROLE_SALES = 'sales';             // فروش - مدیریت سفارش‌ها، گزارش فروش
    public const ROLE_SUPPORT = 'support';         // پشتیبانی - پاسخگویی و پیگیری مشتری

    /**
     * همه‌ی نقش‌های «کارمند داخلی» (در مقابل مشتری). برای چک سریع این‌که
     * آیا یه کاربر اصلاً حق ورود به پنل مدیریت رو داره یا نه.
     */
    public const STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_WAREHOUSE,
        self::ROLE_SALES,
        self::ROLE_SUPPORT,
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_verified',
        'password',
        'otp_password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'otp_password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'otp_password' => 'hashed',
            'phone_verified' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Passport v13 موقع Password Grant این متد رو با ۲ پارامتر صدا می‌زنه.
     * هم با ایمیل هم با موبایل می‌شه لاگین کرد (مشتری معمولاً با موبایل،
     * کارمندهای داخلی معمولاً با ایمیل/پسورد).
     */
    public function findForPassport(string $username, \Laravel\Passport\Bridge\Client $client): ?self
    {
        return self::where('email', $username)
            ->orWhere('phone', $username)
            ->first();
    }

    /**
     * هم password واقعی (کارمندها) هم otp_password موقت (مشتری با OTP)
     * رو قبول می‌کنه، بدون این‌که پسورد واقعی کسی دست‌کاری بشه.
     */
    public function validateForPassportPasswordGrant(string $password): bool
    {
        if ($this->password && \Illuminate\Support\Facades\Hash::check($password, $this->password)) {
            return true;
        }

        if ($this->otp_password && \Illuminate\Support\Facades\Hash::check($password, $this->otp_password)) {
            return true;
        }

        return false;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    // ---------- کمک‌کننده‌های نقش ----------

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isWarehouse(): bool
    {
        return $this->role === self::ROLE_WAREHOUSE;
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    public function isSupport(): bool
    {
        return $this->role === self::ROLE_SUPPORT;
    }

    /**
     * آیا این کاربر اصلاً کارمند داخلیه (در مقابل مشتری)؟
     * برای چک سریع «آیا حق ورود به پنل مدیریت رو داره».
     */
    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }


    public function vehicles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'customer_vehicle');
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Address::class);
    }
}
