<?php

namespace App\Http\Controllers;

use App\Contracts\AccountingProviderContract;
use App\Models\Order;
use RuntimeException;

class AccountingController extends Controller
{
    public function __construct(private AccountingProviderContract $accountingProvider) {}

    /**
     * صدور دستی/مجدد فاکتور برای سفارشی که پرداخت شده - برای وقتی صدور
     * خودکار (بعد از پرداخت) به هر دلیلی شکست خورده باشه.
     */
    public function issueInvoice(Order $order)
    {
        if (! $order->isPaid()) {
            return response()->json(['message' => 'فقط سفارش‌های پرداخت‌شده قابل صدور فاکتورن.'], 422);
        }

        try {
            $invoice = $this->accountingProvider->issueInvoice($order->load(['items', 'user']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'صدور فاکتور شکست خورد: ' . $e->getMessage()], 502);
        }

        $order->update([
            'invoice_number' => $invoice['invoice_number'],
            'invoice_url' => $invoice['invoice_url'] ?? null,
            'invoiced_at' => now(),
        ]);

        return response()->json(['order' => $order->fresh()]);
    }
}
