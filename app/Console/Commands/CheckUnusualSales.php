<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\SalesAlert;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckUnusualSales extends Command
{
    protected $signature = 'sales:check-unusual';

    protected $description = 'بررسی فروش روزانه‌ی هر محصول نسبت به میانگین چندروزه و ثبت هشدار در صورت افزایش غیرعادی (بند ۵ سند)';

    public function handle(SmsService $sms): int
    {
        $periodDays = (int) config('services.sales_alert.period_days', 7);
        $tolerancePercent = (int) config('services.sales_alert.tolerance_percent', 50);

        $today = now()->startOfDay();
        $baselineStart = $today->copy()->subDays($periodDays);
        $baselineEnd = $today->copy()->subSecond(); // درست تا لحظه‌ی قبل از امروز

        // فروش امروز به تفکیک محصول
        $todaySales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_PAID)
            ->whereBetween('orders.paid_at', [$today, $today->copy()->endOfDay()])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'product_id');

        if ($todaySales->isEmpty()) {
            $this->info('امروز هیچ فروشی ثبت نشده؛ چیزی برای بررسی نیست.');

            return self::SUCCESS;
        }

        // میانگین فروش روزانه‌ی همون محصولات توی بازه‌ی مبنا (قبل از امروز)
        $baselineSales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_PAID)
            ->whereIn('order_items.product_id', $todaySales->keys())
            ->whereBetween('orders.paid_at', [$baselineStart, $baselineEnd])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'product_id');

        $alertsCreated = 0;

        foreach ($todaySales as $productId => $actualQuantity) {
            $baselineTotal = (int) ($baselineSales[$productId] ?? 0);
            $averageDaily = $baselineTotal / $periodDays;

            // اگه توی بازه‌ی مبنا اصلاً فروشی نبوده، مقایسه بی‌معنیه (محصول
            // تازه پرفروش شده، نه اینکه نسبت به روال قبلیش غیرعادی باشه)
            if ($averageDaily <= 0) {
                continue;
            }

            $threshold = $averageDaily * (1 + $tolerancePercent / 100);

            if ($actualQuantity > $threshold) {
                SalesAlert::create([
                    'product_id' => $productId,
                    'average_quantity' => round($averageDaily, 2),
                    'actual_quantity' => $actualQuantity,
                    'tolerance_percent' => $tolerancePercent,
                    'period_days' => $periodDays,
                ]);

                $alertsCreated++;

                $this->notifyStaff($sms, $productId, $actualQuantity, $averageDaily);
            }
        }

        $this->info("بررسی تمام شد؛ {$alertsCreated} هشدار فروش غیرعادی ثبت شد.");

        return self::SUCCESS;
    }

    private function notifyStaff(SmsService $sms, int $productId, int $actualQuantity, float $averageDaily): void
    {
        $product = Product::find($productId);
        $title = $product->title ?? "محصول #{$productId}";

        $message = "هشدار فروش غیرعادی: {$title} امروز {$actualQuantity} عدد فروخته شده "
            . '(میانگین روزانه: ' . round($averageDaily, 1) . ' عدد).';

        $mobiles = collect(explode(',', config('services.admin_mobile', '')))
            ->map(fn($m) => trim($m))
            ->filter();

        foreach ($mobiles as $mobile) {
            $sms->send($mobile, $message);
        }
    }
}
