<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeZarinpalVerifySuccess(string $refId = 'REF-1'): array
    {
        return [
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => $refId],
                'errors' => [],
            ], 200),
        ];
    }

    public function test_invoice_issued_automatically_after_successful_payment(): void
    {
        Http::fake(array_merge(
            $this->fakeZarinpalVerifySuccess('REF-999'),
            [
                'localhost:7373/api/Invoices' => Http::response([
                    'InvoiceNumber' => 'INV-AUTO-1',
                    'PrintUrl' => 'https://sepidar.local/print/1',
                ], 200),
            ]
        ));

        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-INV-1',
            'payment_link_expires_at' => now()->addMinutes(20),
        ]);
        $order->items()->create(['title' => 'کالا', 'sku' => 'S1', 'price' => 10000, 'quantity' => 1]);

        $this->get('/api/payment/callback?Authority=AUTH-INV-1&Status=OK')->assertStatus(302);

        $order->refresh();
        $this->assertEquals(Order::STATUS_PAID, $order->status);
        $this->assertEquals('INV-AUTO-1', $order->invoice_number);
        $this->assertNotNull($order->invoiced_at);
    }

    public function test_payment_still_succeeds_when_invoice_issuance_fails(): void
    {
        Http::fake(array_merge(
            $this->fakeZarinpalVerifySuccess('REF-998'),
            [
                'localhost:7373/api/Invoices' => Http::response(['Message' => 'down'], 500),
            ]
        ));

        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-INV-2',
            'payment_link_expires_at' => now()->addMinutes(20),
        ]);
        $order->items()->create(['title' => 'کالا', 'sku' => 'S1', 'price' => 10000, 'quantity' => 1]);

        $this->get('/api/payment/callback?Authority=AUTH-INV-2&Status=OK')->assertStatus(302);

        $order->refresh();

        // پرداخت باید موفق ثبت شده باشه، حتی اگه صدور فاکتور شکست خورده
        $this->assertEquals(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->invoice_number);
    }
}
