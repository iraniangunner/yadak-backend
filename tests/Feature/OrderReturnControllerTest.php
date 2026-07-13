<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderReturnControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    private function createPaidOrderWithItem(User $customer, int $quantity = 2): array
    {
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $item = $order->items()->create([
            'title' => 'لنت ترمز',
            'sku' => 'SKU-1',
            'price' => 50000,
            'quantity' => $quantity,
        ]);

        return [$order, $item];
    }

    public function test_guest_cannot_request_return(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/orders/{$order->id}/returns", [])->assertStatus(401);
    }

    public function test_customer_can_request_return_for_paid_order(): void
    {
        $customer = $this->actingAsCustomer();
        [$order, $item] = $this->createPaidOrderWithItem($customer);

        $response = $this->postJson("/api/orders/{$order->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 1,
            'reason' => 'قطعه معیوب بود',
        ]);

        $response->assertStatus(201)->assertJsonPath('return.status', OrderReturn::STATUS_REQUESTED);

        $this->assertDatabaseHas('order_returns', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'user_id' => $customer->id,
            'quantity' => 1,
        ]);
    }

    public function test_cannot_request_return_for_unpaid_order(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create(['user_id' => $customer->id, 'status' => Order::STATUS_PENDING_REVIEW]);
        $item = $order->items()->create(['title' => 'کالا', 'sku' => 'SKU', 'price' => 10000, 'quantity' => 1]);

        $response = $this->postJson("/api/orders/{$order->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 1,
            'reason' => 'دلیل',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_request_return_for_others_order(): void
    {
        $this->actingAsCustomer();

        $otherOrder = Order::factory()->create(['status' => Order::STATUS_PAID]);
        $item = $otherOrder->items()->create(['title' => 'کالا', 'sku' => 'SKU', 'price' => 10000, 'quantity' => 1]);

        $response = $this->postJson("/api/orders/{$otherOrder->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 1,
            'reason' => 'دلیل',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_request_return_for_item_from_another_order(): void
    {
        $customer = $this->actingAsCustomer();
        [$orderA] = $this->createPaidOrderWithItem($customer);
        [$orderB, $itemB] = $this->createPaidOrderWithItem($customer);

        $response = $this->postJson("/api/orders/{$orderA->id}/returns", [
            'order_item_id' => $itemB->id,
            'quantity' => 1,
            'reason' => 'دلیل',
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_request_return_quantity_more_than_purchased(): void
    {
        $customer = $this->actingAsCustomer();
        [$order, $item] = $this->createPaidOrderWithItem($customer, quantity: 2);

        $response = $this->postJson("/api/orders/{$order->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 3,
            'reason' => 'دلیل',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_request_return_exceeding_remaining_after_previous_request(): void
    {
        $customer = $this->actingAsCustomer();
        [$order, $item] = $this->createPaidOrderWithItem($customer, quantity: 2);

        // اولین درخواست: ۱ عدد از ۲ تا
        $this->postJson("/api/orders/{$order->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 1,
            'reason' => 'اول',
        ])->assertStatus(201);

        // درخواست دوم برای ۲ عدد دیگه - جمعاً می‌شه ۳ که از ۲ تا خریداری‌شده بیشتره
        $response = $this->postJson("/api/orders/{$order->id}/returns", [
            'order_item_id' => $item->id,
            'quantity' => 2,
            'reason' => 'دوم',
        ]);

        $response->assertStatus(422);
    }

    public function test_customer_can_list_only_own_returns(): void
    {
        $customer = $this->actingAsCustomer();
        [$order, $item] = $this->createPaidOrderWithItem($customer);

        OrderReturn::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'user_id' => $customer->id,
            'quantity' => 1,
            'reason' => 'دلیل من',
            'status' => OrderReturn::STATUS_REQUESTED,
        ]);

        OrderReturn::factory()->create(); // متعلق به یه کاربر دیگه

        $response = $this->getJson('/api/returns');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
