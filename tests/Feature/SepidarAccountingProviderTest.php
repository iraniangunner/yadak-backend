<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Accounting\SepidarAccountingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SepidarAccountingProviderTest extends TestCase
{
    use RefreshDatabase;

    private function createPaidOrderWithItems(): Order
    {
        $order = Order::factory()->create(['status' => Order::STATUS_PAID, 'total_amount' => 150000]);

        $order->items()->create([
            'title' => 'لنت ترمز',
            'sku' => 'SKU-1',
            'price' => 100000,
            'quantity' => 1,
        ]);

        $order->items()->create([
            'title' => 'روغن موتور',
            'sku' => 'SKU-2',
            'price' => 50000,
            'quantity' => 1,
        ]);

        return $order->fresh(['items', 'user']);
    }

    public function test_issues_invoice_successfully(): void
    {
        Http::fake([
            'localhost:7373/api/Invoices' => Http::response([
                'InvoiceNumber' => 'INV-1001',
                'PrintUrl' => 'https://sepidar.local/print/1001',
            ], 200),
        ]);

        $order = $this->createPaidOrderWithItems();

        $result = app(SepidarAccountingProvider::class)->issueInvoice($order);

        $this->assertEquals('INV-1001', $result['invoice_number']);
        $this->assertEquals('https://sepidar.local/print/1001', $result['invoice_url']);
    }

    public function test_request_payload_includes_order_items(): void
    {
        Http::fake([
            'localhost:7373/api/Invoices' => Http::response(['InvoiceNumber' => 'INV-1', 'PrintUrl' => null], 200),
        ]);

        $order = $this->createPaidOrderWithItems();

        app(SepidarAccountingProvider::class)->issueInvoice($order);

        Http::assertSent(function ($request) use ($order) {
            $lines = $request->data()['Lines'] ?? [];

            return $request->data()['ExternalOrderID'] === (string) $order->id
                && $request->data()['TotalAmount'] === 150000
                && count($lines) === 2
                && $lines[0]['ItemSKU'] === 'SKU-1'
                && $lines[0]['UnitPrice'] === 100000;
        });
    }

    public function test_throws_exception_on_error_response(): void
    {
        Http::fake([
            'localhost:7373/api/Invoices' => Http::response(['Message' => 'Unauthorized'], 401),
        ]);

        $order = $this->createPaidOrderWithItems();

        $this->expectException(RuntimeException::class);

        app(SepidarAccountingProvider::class)->issueInvoice($order);
    }

    public function test_throws_exception_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $order = $this->createPaidOrderWithItems();

        $this->expectException(RuntimeException::class);

        app(SepidarAccountingProvider::class)->issueInvoice($order);
    }
}
