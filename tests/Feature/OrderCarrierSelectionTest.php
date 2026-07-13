<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderCarrierSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    /**
     * فیک کردن هر سه provider واقعی، تا وقتی OrderController گزینه‌های
     * حمل رو استعلام می‌گیره (برای تطبیق گزینه‌ی انتخابی)، کرش نکنه.
     */
    private function fakeAllProviders(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.snappbox.ir/*' => Http::response([
                'services' => [['title' => 'استاندارد', 'fee' => 22000, 'eta_hours' => 5]],
            ], 200),
            'api.tipax.ir/*' => Http::response([
                'data' => [['service_name' => 'اکسپرس', 'price' => 28000, 'estimated_days' => 1]],
            ], 200),
            'api.post.ir/*' => Http::response([
                'price' => 20000,
                'delivery_days' => 3,
            ], 200),
        ], $overrides));
    }

    public function test_order_with_selected_carrier_uses_its_cost(): void
    {
        $customer = $this->actingAsCustomer();
        $this->fakeAllProviders();

        $address = Address::factory()->create(['user_id' => $customer->id, 'city' => 'تهران']);
        $product = Product::factory()->create(['price' => 100000, 'weight_kg' => 0]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $address->id,
            'shipping_carrier' => 'تیپاکس',
            'shipping_service_name' => 'اکسپرس',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.shipping_cost', 28000)
            ->assertJsonPath('order.shipping_carrier', 'تیپاکس')
            ->assertJsonPath('order.shipping_service_name', 'اکسپرس')
            ->assertJsonPath('order.total_amount', 128000);

        $order = Order::first();
        $this->assertEquals('تیپاکس', $order->shipping_carrier);
    }

    public function test_order_fails_with_nonexistent_carrier_option(): void
    {
        $customer = $this->actingAsCustomer();
        $this->fakeAllProviders();

        $address = Address::factory()->create(['user_id' => $customer->id]);
        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $address->id,
            'shipping_carrier' => 'شرکت-ناموجود',
            'shipping_service_name' => 'سرویس-ناموجود',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_with_carrier_but_no_service_name(): void
    {
        // چون این validation-error هست، به provider اصلاً نیازی نیست
        // (رد می‌شه قبل از رسیدن به مرحله‌ی استعلام گزینه‌ها).
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_carrier' => 'پست',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('shipping_service_name');
    }

    public function test_order_with_address_but_no_carrier_uses_base_rate(): void
    {
        // اینجا هیچ شرکتی انتخاب نشده، پس اصلاً provider صدا زده نمی‌شه؛
        // فقط از ShippingCostCalculator داخلی (جدول shipping_rates) استفاده می‌شه.
        $customer = $this->actingAsCustomer();

        ShippingRate::factory()->create(['city' => null, 'base_price' => 15000, 'price_per_kg' => 0]);

        $address = Address::factory()->create(['user_id' => $customer->id]);
        $product = Product::factory()->create(['price' => 100000, 'weight_kg' => 0]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address_id' => $address->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.shipping_cost', 15000)
            ->assertJsonPath('order.shipping_carrier', null);
    }

    public function test_order_without_any_shipping_info_has_null_carrier(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.shipping_cost', 0)
            ->assertJsonPath('order.shipping_carrier', null);
    }
}
