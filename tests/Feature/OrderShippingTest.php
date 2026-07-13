<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderShippingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    public function test_order_without_shipping_address_has_zero_shipping_cost(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.shipping_cost', 0)
            ->assertJsonPath('order.total_amount', 100000);

        $order = Order::first();
        $this->assertNull($order->shipping_address_id);
        $this->assertNull($order->shipping_city);
    }

    public function test_order_with_shipping_address_computes_cost_and_snapshot(): void
    {
        $customer = $this->actingAsCustomer();

        ShippingRate::factory()->create(['city' => 'تهران', 'base_price' => 20000, 'price_per_kg' => 5000]);

        $address = Address::factory()->create([
            'user_id' => $customer->id,
            'city' => 'تهران',
            'receiver_name' => 'علی رضایی',
            'receiver_phone' => '09121234567',
            'full_address' => 'خیابان آزادی',
        ]);

        $product = Product::factory()->create(['price' => 100000, 'weight_kg' => 2]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $address->id,
        ]);

        // هزینه‌ی ارسال: ۲۰۰۰۰ + (۵۰۰۰ * ۲ کیلو) = ۳۰۰۰۰
        $response->assertStatus(201)
            ->assertJsonPath('order.shipping_cost', 30000)
            ->assertJsonPath('order.total_amount', 130000);

        $order = Order::first();
        $this->assertEquals($address->id, $order->shipping_address_id);
        $this->assertEquals('علی رضایی', $order->shipping_receiver_name);
        $this->assertEquals('09121234567', $order->shipping_receiver_phone);
        $this->assertEquals('تهران', $order->shipping_city);
        $this->assertEquals('خیابان آزادی', $order->shipping_full_address);
    }

    public function test_order_fails_with_others_shipping_address(): void
    {
        $this->actingAsCustomer();

        $othersAddress = Address::factory()->create();
        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $othersAddress->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_shipping_cost_included_with_coupon_discount(): void
    {
        $customer = $this->actingAsCustomer();

        ShippingRate::factory()->create(['city' => null, 'base_price' => 10000, 'price_per_kg' => 0]);

        $address = Address::factory()->create(['user_id' => $customer->id, 'city' => 'شهر-بدون-نرخ-خاص']);
        $product = Product::factory()->create(['price' => 100000, 'weight_kg' => 0]);

        Coupon::factory()->create(['code' => 'SHIP10', 'type' => 'percentage', 'value' => 10]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $address->id,
            'coupon_code' => 'SHIP10',
        ]);

        // subtotal=100000, discount=10%(10000), shipping=10000 → total=100000-10000+10000=100000
        $response->assertStatus(201)
            ->assertJsonPath('order.discount_amount', 10000)
            ->assertJsonPath('order.shipping_cost', 10000)
            ->assertJsonPath('order.total_amount', 100000);
    }
}
