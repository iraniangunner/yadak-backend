<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ساخت لیست مخاطبان پیامک گروهی بر اساس فیلترهای رفتاری/اطلاعاتی
 * (بند ۶ سند: «نوع خودرو، سابقه خرید، خرید یک کالای خاص، عدم خرید در
 * بازه زمانی مشخص یا شهر مشتری»). همه‌ی فیلترها با AND ترکیب می‌شن.
 */
class CustomerAudienceFilter
{
    /**
     * فیلترهای پشتیبانی‌شده:
     * - vehicle_id: مشتریانی که این خودرو رو توی حسابشون ثبت کردن
     * - purchased_product_id: مشتریانی که این محصول رو (توی سفارش پرداخت‌شده) خریدن
     * - has_purchased: true/false - آیا تا حالا خرید موفق داشته یا نه
     * - no_purchase_since: تاریخ - مشتریانی که از این تاریخ به بعد خرید موفقی نداشتن
     * - city: شهر مشتری
     */
    public function buildQuery(array $filters): Builder
    {
        $query = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->whereNotNull('phone');

        if (! empty($filters['vehicle_id'])) {
            $vehicleId = $filters['vehicle_id'];
            $query->whereHas('vehicles', fn($q) => $q->where('vehicles.id', $vehicleId));
        }

        if (! empty($filters['purchased_product_id'])) {
            $productId = $filters['purchased_product_id'];
            $query->whereHas('orders', function ($q) use ($productId) {
                $q->where('status', Order::STATUS_PAID)
                    ->whereHas('items', fn($q2) => $q2->where('product_id', $productId));
            });
        }

        if (array_key_exists('has_purchased', $filters) && $filters['has_purchased'] !== null) {
            if ($filters['has_purchased']) {
                $query->whereHas('orders', fn($q) => $q->where('status', Order::STATUS_PAID));
            } else {
                $query->whereDoesntHave('orders', fn($q) => $q->where('status', Order::STATUS_PAID));
            }
        }

        if (! empty($filters['no_purchase_since'])) {
            $since = $filters['no_purchase_since'];
            $query->whereDoesntHave('orders', function ($q) use ($since) {
                $q->where('status', Order::STATUS_PAID)->where('paid_at', '>=', $since);
            });
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        return $query;
    }
}
