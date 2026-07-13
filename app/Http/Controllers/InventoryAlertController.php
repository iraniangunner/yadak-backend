<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    /**
     * یه نگاه واحد به سه چیزی که انبار/ادمین باید بهش رسیدگی کنه:
     * ۱. محصولاتی که ناموجود/متوقفن، به‌همراه تعداد مشتری‌های منتظر اطلاع‌رسانی
     * ۲. سفارش‌هایی که مدت زیادیه توی صف «در انتظار بررسی موجودی» موندن
     * ۳. جمع‌بندی سریع (تعداد کل هرکدوم) برای نمایش روی داشبورد
     */
    public function index(Request $request)
    {
        $staleHours = (int) config('services.inventory_alert.stale_order_hours', 24);

        $lowStockProducts = Product::query()
            ->whereIn('stock_status', [Product::STATUS_OUT_OF_STOCK, Product::STATUS_STOPPED])
            ->withCount(['stockSubscriptions as waiting_customers_count' => fn($q) => $q->where('notified', false)])
            ->orderByDesc('waiting_customers_count')
            ->get(['id', 'title', 'sku', 'stock_status']);

        $staleOrders = Order::query()
            ->where('status', Order::STATUS_PENDING_REVIEW)
            ->where('created_at', '<=', now()->subHours($staleHours))
            ->with('user:id,name,phone')
            ->oldest()
            ->get(['id', 'user_id', 'total_amount', 'created_at']);

        return response()->json([
            'low_stock_products' => $lowStockProducts,
            'stale_pending_orders' => $staleOrders,
            'summary' => [
                'low_stock_count' => $lowStockProducts->count(),
                'stale_orders_count' => $staleOrders->count(),
                'stale_order_threshold_hours' => $staleHours,
            ],
        ]);
    }
}
