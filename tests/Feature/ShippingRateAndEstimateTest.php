<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ShippingRateAndEstimateTest extends TestCase
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
    // ShippingRateController
    // ------------------------------------------------------------------

    public function test_guest_cannot_manage_shipping_rates(): void
    {
        $this->getJson('/api/admin/shipping-rates')->assertStatus(401);
    }

    public function test_non_admin_cannot_manage_shipping_rates(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/shipping-rates')->assertStatus(403);
    }

    public function test_admin_can_create_city_specific_rate(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/shipping-rates', [
            'city' => 'تهران',
            'base_price' => 20000,
            'price_per_kg' => 5000,
        ]);

        $response->assertStatus(201)->assertJsonPath('rate.city', 'تهران');
    }

    public function test_admin_can_create_default_rate(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/shipping-rates', [
            'city' => null,
            'base_price' => 30000,
            'price_per_kg' => 10000,
        ]);

        $response->assertStatus(201)->assertJsonPath('rate.city', null);
    }

    public function test_store_fails_with_duplicate_city(): void
    {
        $this->actingAsAdmin();

        ShippingRate::factory()->create(['city' => 'تهران']);

        $response = $this->postJson('/api/admin/shipping-rates', [
            'city' => 'تهران',
            'base_price' => 20000,
            'price_per_kg' => 5000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('city');
    }

    public function test_admin_can_update_rate(): void
    {
        $this->actingAsAdmin();

        $rate = ShippingRate::factory()->create(['base_price' => 20000]);

        $response = $this->putJson("/api/admin/shipping-rates/{$rate->id}", ['base_price' => 25000]);

        $response->assertStatus(200)->assertJsonPath('rate.base_price', 25000);
    }

    public function test_admin_can_delete_rate(): void
    {
        $this->actingAsAdmin();

        $rate = ShippingRate::factory()->create();

        $this->deleteJson("/api/admin/shipping-rates/{$rate->id}")->assertStatus(200);

        $this->assertDatabaseMissing('shipping_rates', ['id' => $rate->id]);
    }

    // ------------------------------------------------------------------
    // ShippingEstimateController
    // ------------------------------------------------------------------

    public function test_estimate_uses_city_specific_rate(): void
    {
        ShippingRate::factory()->create(['city' => 'تهران', 'base_price' => 20000, 'price_per_kg' => 5000]);
        ShippingRate::factory()->create(['city' => null, 'base_price' => 30000, 'price_per_kg' => 10000]);

        $product = Product::factory()->create(['weight_kg' => 2]);

        $response = $this->postJson('/api/shipping/estimate', [
            'city' => 'تهران',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        // ۲۰۰۰۰ + (۵۰۰۰ * ۲ کیلو) = ۳۰۰۰۰
        $response->assertStatus(200)
            ->assertJsonPath('shipping_cost', 30000)
            ->assertJsonPath('total_weight_kg', 2);
    }

    public function test_estimate_falls_back_to_default_rate_for_unknown_city(): void
    {
        ShippingRate::factory()->create(['city' => 'تهران', 'base_price' => 20000, 'price_per_kg' => 5000]);
        ShippingRate::factory()->create(['city' => null, 'base_price' => 30000, 'price_per_kg' => 10000]);

        $product = Product::factory()->create(['weight_kg' => 1]);

        $response = $this->postJson('/api/shipping/estimate', [
            'city' => 'یه‌شهر‌ناشناخته',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        // باید از نرخ پیش‌فرض استفاده کنه: ۳۰۰۰۰ + (۱۰۰۰۰ * ۱) = ۴۰۰۰۰
        $response->assertStatus(200)->assertJsonPath('shipping_cost', 40000);
    }

    public function test_estimate_returns_zero_when_no_rates_configured(): void
    {
        $product = Product::factory()->create(['weight_kg' => 5]);

        $response = $this->postJson('/api/shipping/estimate', [
            'city' => 'تهران',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(200)->assertJsonPath('shipping_cost', 0);
    }

    public function test_estimate_fails_without_city_or_items(): void
    {
        $response = $this->postJson('/api/shipping/estimate', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['city', 'items']);
    }

    public function test_estimate_sums_weight_across_multiple_items(): void
    {
        ShippingRate::factory()->create(['city' => null, 'base_price' => 10000, 'price_per_kg' => 1000]);

        $productA = Product::factory()->create(['weight_kg' => 1]);
        $productB = Product::factory()->create(['weight_kg' => 2]);

        $response = $this->postJson('/api/shipping/estimate', [
            'city' => 'تهران',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2], // 2kg
                ['product_id' => $productB->id, 'quantity' => 1], // 2kg
            ],
        ]);

        // وزن کل: ۴ کیلو → ۱۰۰۰۰ + (۱۰۰۰ * ۴) = ۱۴۰۰۰
        $response->assertStatus(200)
            ->assertJsonPath('total_weight_kg', 4)
            ->assertJsonPath('shipping_cost', 14000);
    }
}
