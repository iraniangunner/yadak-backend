<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    // ------------------------------------------------------------------
    // دسترسی
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_admin_orders(): void
    {
        $this->getJson('/api/admin/orders')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_admin_orders(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/admin/orders')->assertStatus(403);
    }

    public function test_warehouse_can_access_admin_orders(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/orders')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // index / show
    // ------------------------------------------------------------------

    public function test_admin_can_list_and_filter_orders_by_status(): void
    {
        $this->actingAsAdmin();

        Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);
        Order::factory()->create(['status' => Order::STATUS_PAID]);

        $response = $this->getJson('/api/admin/orders?status=' . Order::STATUS_PENDING_REVIEW);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_view_order_detail_with_items(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create();
        $order->items()->create([
            'product_id' => Product::factory()->create()->id,
            'title' => 'محصول تستی',
            'sku' => 'SKU1',
            'price' => 10000,
            'quantity' => 1,
        ]);

        $response = $this->getJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'order.items');
    }

    // ------------------------------------------------------------------
    // approve
    // ------------------------------------------------------------------

    public function test_admin_can_approve_pending_order(): void
    {
        $admin = $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/approve");

        $response->assertStatus(200)->assertJsonPath('order.status', Order::STATUS_AWAITING_PAYMENT);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_AWAITING_PAYMENT,
            'changed_by' => $admin->id,
        ]);
    }

    public function test_approve_fails_if_order_not_pending_review(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_AWAITING_PAYMENT]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/approve");

        $response->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // updateItems
    // ------------------------------------------------------------------

    public function test_admin_can_mark_item_unavailable_and_recompute_total(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING_REVIEW,
            'subtotal' => 150000,
            'total_amount' => 150000,
        ]);

        $availableItem = $order->items()->create([
            'title' => 'کالای موجود',
            'sku' => 'SKU1',
            'price' => 100000,
            'quantity' => 1,
        ]);
        $unavailableItem = $order->items()->create([
            'title' => 'کالای ناموجود',
            'sku' => 'SKU2',
            'price' => 50000,
            'quantity' => 1,
        ]);

        $response = $this->putJson("/api/admin/orders/{$order->id}/items", [
            'items' => [
                ['id' => $availableItem->id, 'quantity' => 1, 'is_available' => true],
                ['id' => $unavailableItem->id, 'quantity' => 1, 'is_available' => false],
            ],
            'admin_note' => 'کالای دوم موجود نبود.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.status', Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION)
            ->assertJsonPath('order.subtotal', 100000)
            ->assertJsonPath('order.total_amount', 100000);

        $this->assertDatabaseHas('order_items', [
            'id' => $unavailableItem->id,
            'is_available' => false,
            'removed_by_admin' => true,
        ]);
    }

    public function test_update_items_fails_with_item_from_another_order(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);
        $otherOrder = Order::factory()->create();

        $foreignItem = $otherOrder->items()->create([
            'title' => 'کالا',
            'sku' => 'SKU-X',
            'price' => 10000,
            'quantity' => 1,
        ]);

        $response = $this->putJson("/api/admin/orders/{$order->id}/items", [
            'items' => [
                ['id' => $foreignItem->id, 'quantity' => 1, 'is_available' => true],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_items_fails_if_order_not_pending_review(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_AWAITING_PAYMENT]);
        $item = $order->items()->create(['title' => 'کالا', 'sku' => 'SKU', 'price' => 10000, 'quantity' => 1]);

        $response = $this->putJson("/api/admin/orders/{$order->id}/items", [
            'items' => [['id' => $item->id, 'quantity' => 1, 'is_available' => true]],
        ]);

        $response->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // cancel
    // ------------------------------------------------------------------

    public function test_admin_can_cancel_open_order_with_note(): void
    {
        $admin = $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/cancel", [
            'note' => 'هیچ‌کدام از اقلام موجود نبود.',
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_CANCELLED,
            'changed_by' => $admin->id,
            'note' => 'هیچ‌کدام از اقلام موجود نبود.',
        ]);
    }

    public function test_admin_cannot_cancel_already_paid_order(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);

        $response = $this->postJson("/api/admin/orders/{$order->id}/cancel");

        $response->assertStatus(422);
    }
}
