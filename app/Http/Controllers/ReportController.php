<?php

namespace App\Http\Controllers;

use App\Exports\CitySalesExport;
use App\Exports\CustomerSalesExport;
use App\Exports\ProductSalesExport;
use App\Exports\ReturnsExport;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * اگه بایت نامعتبر UTF-8 توی داده باشه، به‌جای کرش کردن json_encode،
     * فقط اون کاراکتر خراب رو با یه کاراکتر جایگزین (�) نشون بده.
     */


    /**
     * بازه‌ی تاریخ مشترک بین همه‌ی گزارش‌ها. اگه از/تا داده نشه، کل تاریخچه.
     */
    private function dateRange(Request $request): array
    {
        return [
            $request->filled('from') ? $request->date('from')->startOfDay() : null,
            $request->filled('to') ? $request->date('to')->endOfDay() : null,
        ];
    }

    /**
     * چون این گزارش‌ها روی query هایی با GROUP BY/selectRaw ساخته شدن،
     * paginate() استاندارد لاراول (که برای COUNT از خودِ query استفاده
     * می‌کنه) با GROUP BY درست کار نمی‌کنه. برای همین همه‌ی ردیف‌ها رو
     * می‌گیریم و خودمون توی PHP صفحه‌بندی می‌کنیم - برای گزارش‌های ادمین
     * (چندصد/چندهزار ردیف حداکثر) کاملاً کافیه.
     */
    private function paginateCollection(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = $request->integer('per_page', 20);
        $page = $request->integer('page', 1);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * گزارش فروش کالاها: تعداد و مبلغ فروش هر محصول، فقط از سفارش‌های
     * پرداخت‌شده (paid).
     */
    public function productSales(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_PAID)
            ->when($from, fn($q) => $q->where('orders.paid_at', '>=', $from))
            ->when($to, fn($q) => $q->where('orders.paid_at', '<=', $to))
            ->groupBy('order_items.product_id', 'order_items.title', 'order_items.sku')
            ->selectRaw('order_items.product_id, order_items.title, order_items.sku,
                SUM(order_items.quantity) as total_quantity,
                SUM(order_items.price * order_items.quantity) as total_revenue')
            ->orderByDesc('total_revenue')
            ->get();

        if ($request->string('format')->toString() === 'xlsx') {
            return Excel::download(new ProductSalesExport($rows), 'product-sales.xlsx');
        }

        return response()->json($this->paginateCollection($rows, $request), 200, []);
    }

    /**
     * گزارش فروش/سوابق خرید مشتریان: تعداد سفارش و مجموع خرید هر مشتری.
     */
    public function customerSales(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        $rows = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', Order::STATUS_PAID)
            ->when($from, fn($q) => $q->where('orders.paid_at', '>=', $from))
            ->when($to, fn($q) => $q->where('orders.paid_at', '<=', $to))
            ->groupBy('orders.user_id', 'users.name', 'users.phone')
            ->selectRaw('orders.user_id, users.name, users.phone,
                COUNT(orders.id) as total_orders,
                SUM(orders.total_amount) as total_spent')
            ->orderByDesc('total_spent')
            ->get();

        if ($request->string('format')->toString() === 'xlsx') {
            return Excel::download(new CustomerSalesExport($rows), 'customer-sales.xlsx');
        }

        return response()->json($this->paginateCollection($rows, $request), 200, []);
    }

    /**
     * گزارش فروش بر اساس شهر مشتری.
     */
    public function citySales(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        $rows = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', Order::STATUS_PAID)
            ->when($from, fn($q) => $q->where('orders.paid_at', '>=', $from))
            ->when($to, fn($q) => $q->where('orders.paid_at', '<=', $to))
            ->groupBy('users.city')
            ->selectRaw('users.city,
                COUNT(orders.id) as total_orders,
                SUM(orders.total_amount) as total_sales')
            ->orderByDesc('total_sales')
            ->get();

        if ($request->string('format')->toString() === 'xlsx') {
            return Excel::download(new CitySalesExport($rows), 'city-sales.xlsx');
        }

        return response()->json($this->paginateCollection($rows, $request), 200, []);
    }

    /**
     * گزارش مرجوعی‌ها، قابل فیلتر بر اساس وضعیت و بازه‌ی تاریخ.
     */
    public function returns(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        $query = OrderReturn::query()
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->string('status')))
            ->when($from, fn($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn($q) => $q->where('created_at', '<=', $to))
            ->with(['user:id,name', 'orderItem:id,title'])
            ->latest();

        if ($request->string('format')->toString() === 'xlsx') {
            return Excel::download(new ReturnsExport($query->get()), 'returns.xlsx');
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 30)),
            200,
            []
        );
    }
}
