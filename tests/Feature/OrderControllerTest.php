<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    // ------------------------------------------------------------------
    // دسترسی
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_order_routes(): void
    {
        $this->getJson('/api/orders')->assertStatus(401);
        $this->postJson('/api/orders', [])->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_customer_can_create_order_from_items(): void
    {
        $this->actingAsCustomer();

        $productA = Product::factory()->create(['price' => 100000]);
        $productB = Product::factory()->create(['price' => 50000]);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.status', Order::STATUS_PENDING_REVIEW)
            ->assertJsonPath('order.subtotal', 250000)
            ->assertJsonPath('order.total_amount', 250000);

        $order = Order::first();
        $this->assertCount(2, $order->items);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'title' => $productA->title,
            'sku' => $productA->sku,
            'price' => 100000,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_store_fails_without_items(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/orders', []);

        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_store_fails_with_inactive_product(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['is_active' => false]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_store_fails_with_invalid_quantity(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create();

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 0]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    // ------------------------------------------------------------------
    // index / show
    // ------------------------------------------------------------------

    public function test_customer_can_list_only_own_orders(): void
    {
        $customer = $this->actingAsCustomer();

        Order::factory()->create(['user_id' => $customer->id]);
        Order::factory()->create(); // متعلق به کاربر دیگه

        $response = $this->getJson('/api/orders');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $this->actingAsCustomer();

        $otherOrder = Order::factory()->create();

        $response = $this->getJson("/api/orders/{$otherOrder->id}");

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // confirm
    // ------------------------------------------------------------------

    public function test_customer_can_confirm_order_needing_confirmation(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertStatus(200)->assertJsonPath('order.status', Order::STATUS_AWAITING_PAYMENT);

        $order->refresh();
        $this->assertNotNull($order->confirmed_by_customer_at);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION,
            'to_status' => Order::STATUS_AWAITING_PAYMENT,
        ]);
    }

    public function test_confirm_fails_if_order_not_in_correct_status(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PENDING_REVIEW,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertStatus(422);
    }

    public function test_customer_cannot_confirm_others_order(): void
    {
        $this->actingAsCustomer();

        $otherOrder = Order::factory()->create(['status' => Order::STATUS_NEEDS_CUSTOMER_CONFIRMATION]);

        $response = $this->postJson("/api/orders/{$otherOrder->id}/confirm");

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // cancel
    // ------------------------------------------------------------------

    public function test_customer_can_cancel_open_order(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PENDING_REVIEW,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_cannot_cancel_paid_order(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PAID,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/cancel");

        $response->assertStatus(422);
    }
}
