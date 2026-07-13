<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pricing.rounding_step' => 1000]);
    }

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    // ------------------------------------------------------------------
    // اعمال موفق
    // ------------------------------------------------------------------

    public function test_customer_can_apply_valid_percentage_coupon(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        Coupon::factory()->create(['code' => 'SAVE10', 'type' => Coupon::TYPE_PERCENTAGE, 'value' => 10]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'coupon_code' => 'save10', // با حروف کوچیک می‌فرستیم، باید match بشه
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.subtotal', 200000)
            ->assertJsonPath('order.discount_amount', 20000)
            ->assertJsonPath('order.total_amount', 180000);

        $order = Order::first();
        $this->assertNotNull($order->coupon_id);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $order->coupon_id,
            'user_id' => $order->user_id,
            'discount_amount' => 20000,
        ]);

        $this->assertEquals(1, Coupon::first()->used_count);
    }

    public function test_fixed_coupon_discount_never_exceeds_subtotal(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 30000]);
        Coupon::factory()->create(['code' => 'BIGFIXED', 'type' => Coupon::TYPE_FIXED, 'value' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'BIGFIXED',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.discount_amount', 30000) // نه ۱۰۰۰۰۰، سقفش subtotal هست
            ->assertJsonPath('order.total_amount', 0);
    }

    public function test_max_discount_amount_caps_percentage_coupon(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 1000000]);
        Coupon::factory()->create([
            'code' => 'CAPPED',
            'type' => Coupon::TYPE_PERCENTAGE,
            'value' => 50,
            'max_discount_amount' => 100000,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'CAPPED',
        ]);

        // ۵۰٪ از ۱،۰۰۰،۰۰۰ می‌شه ۵۰۰،۰۰۰ ولی سقفش ۱۰۰،۰۰۰ هست
        $response->assertStatus(201)->assertJsonPath('order.discount_amount', 100000);
    }

    // ------------------------------------------------------------------
    // رد شدن
    // ------------------------------------------------------------------

    public function test_order_fails_with_nonexistent_coupon_code(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'NOTREAL',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_with_inactive_coupon(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        Coupon::factory()->create(['code' => 'OFFCODE', 'is_active' => false]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'OFFCODE',
        ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_with_expired_coupon(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        Coupon::factory()->create([
            'code' => 'EXPIRED',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'EXPIRED',
        ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_when_below_minimum_cart_amount(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 50000]);
        Coupon::factory()->create(['code' => 'MIN200K', 'min_cart_amount' => 200000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'MIN200K',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_when_global_usage_limit_reached(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        Coupon::factory()->create(['code' => 'LIMITED', 'usage_limit' => 1, 'used_count' => 1]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'LIMITED',
        ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_when_user_already_used_coupon_up_to_limit(): void
    {
        $customer = $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        $coupon = Coupon::factory()->create(['code' => 'ONEPERUSER', 'usage_limit_per_user' => 1]);

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $customer->id,
            'discount_amount' => 10000,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'ONEPERUSER',
        ]);

        $response->assertStatus(422);
    }

    public function test_different_user_can_still_use_coupon_with_per_user_limit(): void
    {
        /** @var \App\Models\User $otherCustomer */
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $coupon = Coupon::factory()->create(['code' => 'ONEPERUSER2', 'usage_limit_per_user' => 1]);

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $otherCustomer->id,
            'discount_amount' => 10000,
        ]);

        $this->actingAsCustomer(); // یه کاربر جدید و متفاوت

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'ONEPERUSER2',
        ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // بازمحاسبه بعد از ویرایش ادمین
    // ------------------------------------------------------------------

    public function test_admin_editing_items_rescales_percentage_coupon_discount(): void
    {
        $this->actingAsCustomer();

        $productA = Product::factory()->create(['price' => 100000]);
        $productB = Product::factory()->create(['price' => 100000]);
        $coupon = Coupon::factory()->create(['code' => 'RESCALE10', 'type' => Coupon::TYPE_PERCENTAGE, 'value' => 10]);

        $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 1],
                ['product_id' => $productB->id, 'quantity' => 1],
            ],
            'coupon_code' => 'RESCALE10',
        ]);

        $order = Order::first();
        $this->assertEquals(20000, $order->discount_amount); // ۱۰٪ از ۲۰۰،۰۰۰

        $itemA = $order->items()->where('product_id', $productA->id)->first();
        $itemB = $order->items()->where('product_id', $productB->id)->first();

        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        // ادمین یکی از دو آیتم رو ناموجود اعلام می‌کنه؛ subtotal نصف می‌شه
        $response = $this->putJson("/api/admin/orders/{$order->id}/items", [
            'items' => [
                ['id' => $itemA->id, 'quantity' => 1, 'is_available' => true],
                ['id' => $itemB->id, 'quantity' => 1, 'is_available' => false],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.subtotal', 100000)
            ->assertJsonPath('order.discount_amount', 10000) // ۱۰٪ از ۱۰۰،۰۰۰ جدید
            ->assertJsonPath('order.total_amount', 90000);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'discount_amount' => 10000,
        ]);
    }
}
