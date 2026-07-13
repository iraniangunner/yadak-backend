<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * قرارداد اتصال به نرم‌افزار حسابداری برای صدور فاکتور برخط (بند ۷ سند:
 * «اتصال سایت به نرم‌افزار حسابداری جهت صدور فاکتور به صورت برخط از
 * طریق API تهیه‌شده توسط شرکت‌های مذکور»).
 *
 * وقتی قرارداد واقعی با یه نرم‌افزار حسابداری (سپیدار، حساب‌فا، پارسیان
 * و...) بسته شد، کافیه یه کلاس جدید implements این interface بنویسی که
 * واقعاً به API اون نرم‌افزار وصل بشه، و توی AppServiceProvider بایندینگ
 * رو عوض کنی - نه Controller نه فرآیند پرداخت نیاز به تغییر دارن.
 */
interface AccountingProviderContract
{
    /**
     * صدور فاکتور برای یه سفارش پرداخت‌شده.
     *
     * @return array{invoice_number: string, invoice_url: ?string}
     *
     * @throws \RuntimeException اگه صدور فاکتور شکست بخوره
     */
    public function issueInvoice(Order $order): array;
}
