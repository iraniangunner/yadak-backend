<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AccountingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    private function fakeSepidarSuccess(string $invoiceNumber = 'INV-500'): void
    {
        Http::fake([
            'localhost:7373/api/Invoices' => Http::response([
                'InvoiceNumber' => $invoiceNumber,
                'PrintUrl' => 'https://sepidar.local/print/500',
            ], 200),
        ]);
    }

    public function test_guest_cannot_issue_invoice(): void
    {
        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);

        $this->postJson("/api/admin/orders/{$order->id}/issue-invoice")->assertStatus(401);
    }

    public function test_non_admin_cannot_issue_invoice(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);

        $this->postJson("/api/admin/orders/{$order->id}/issue-invoice")->assertStatus(403);
    }

    public function test_admin_can_issue_invoice_for_paid_order(): void
    {
        $this->actingAsAdmin();
        $this->fakeSepidarSuccess('INV-777');

        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);
        $order->items()->create(['title' => 'کالا', 'sku' => 'S1', 'price' => 10000, 'quantity' => 1]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/issue-invoice");

        $response->assertStatus(200)->assertJsonPath('order.invoice_number', 'INV-777');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'invoice_number' => 'INV-777',
        ]);
    }

    public function test_fails_for_unpaid_order(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/issue-invoice");

        $response->assertStatus(422);
    }

    public function test_returns_502_when_sepidar_fails(): void
    {
        $this->actingAsAdmin();

        Http::fake([
            'localhost:7373/api/Invoices' => Http::response(['Message' => 'error'], 500),
        ]);

        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);
        $order->items()->create(['title' => 'کالا', 'sku' => 'S1', 'price' => 10000, 'quantity' => 1]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/issue-invoice");

        $response->assertStatus(502);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'invoice_number' => null,
        ]);
    }
}
