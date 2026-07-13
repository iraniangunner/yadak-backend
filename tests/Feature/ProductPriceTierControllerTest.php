<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductPriceTierControllerTest extends TestCase
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

    public function test_guest_cannot_manage_price_tiers(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/admin/products/{$product->id}/price-tiers", [])->assertStatus(401);
    }

    public function test_non_admin_cannot_manage_price_tiers(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $product = Product::factory()->create();

        $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 1,
            'price' => 10000,
        ])->assertStatus(403);
    }

    public function test_warehouse_can_manage_price_tiers(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $product = Product::factory()->create();

        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 1,
            'max_quantity' => 3,
            'price' => 90000,
        ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_price_tier(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 4,
            'max_quantity' => null,
            'price' => 90000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('tier.min_quantity', 4)
            ->assertJsonPath('tier.price', 90000);

        $this->assertDatabaseHas('product_price_tiers', [
            'product_id' => $product->id,
            'min_quantity' => 4,
            'price' => 90000,
        ]);
    }

    public function test_store_fails_with_min_quantity_less_than_one(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 0,
            'price' => 10000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('min_quantity');
    }

    public function test_store_fails_when_max_quantity_less_than_min(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 5,
            'max_quantity' => 3,
            'price' => 10000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('max_quantity');
    }

    public function test_store_fails_with_overlapping_range(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 5, 'price' => 100000]);

        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 3,
            'max_quantity' => 10,
            'price' => 90000,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_allows_adjacent_non_overlapping_ranges(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);

        // بازه‌ی بعدی از ۴ شروع می‌شه، تداخلی با ۱-۳ نداره
        $response = $this->postJson("/api/admin/products/{$product->id}/price-tiers", [
            'min_quantity' => 4,
            'max_quantity' => null,
            'price' => 90000,
        ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_admin_can_update_price_tier(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $tier = $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);

        $response = $this->putJson("/api/admin/products/{$product->id}/price-tiers/{$tier->id}", [
            'price' => 95000,
        ]);

        $response->assertStatus(200)->assertJsonPath('tier.price', 95000);
    }

    public function test_update_fails_with_overlap_against_another_tier(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);
        $tierB = $product->priceTiers()->create(['min_quantity' => 4, 'max_quantity' => 10, 'price' => 90000]);

        // تلاش برای گسترش بازه‌ی B تا جایی که با A تداخل کنه
        $response = $this->putJson("/api/admin/products/{$product->id}/price-tiers/{$tierB->id}", [
            'min_quantity' => 2,
        ]);

        $response->assertStatus(422);
    }

    public function test_update_fails_for_tier_belonging_to_another_product(): void
    {
        $this->actingAsAdmin();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $tier = $productB->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);

        $response = $this->putJson("/api/admin/products/{$productA->id}/price-tiers/{$tier->id}", [
            'price' => 50000,
        ]);

        $response->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    public function test_admin_can_delete_price_tier(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $tier = $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);

        $this->deleteJson("/api/admin/products/{$product->id}/price-tiers/{$tier->id}")->assertStatus(200);

        $this->assertDatabaseMissing('product_price_tiers', ['id' => $tier->id]);
    }

    public function test_destroy_fails_for_tier_belonging_to_another_product(): void
    {
        $this->actingAsAdmin();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $tier = $productB->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);

        $response = $this->deleteJson("/api/admin/products/{$productA->id}/price-tiers/{$tier->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('product_price_tiers', ['id' => $tier->id]);
    }
}
