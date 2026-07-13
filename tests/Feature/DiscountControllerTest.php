<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DiscountControllerTest extends TestCase
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

    public function test_guest_cannot_access_discounts(): void
    {
        $this->getJson('/api/admin/discounts')->assertStatus(401);
    }

    public function test_non_admin_cannot_manage_discounts(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->postJson('/api/admin/discounts', [])->assertStatus(403);
    }

    public function test_warehouse_can_also_manage_discounts(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/discounts')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_discount_on_product(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => 'percentage',
            'value' => 20,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('discount.discountable_type', 'product')
            ->assertJsonPath('discount.value', 20);

        $this->assertDatabaseHas('discounts', [
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
        ]);
    }

    public function test_admin_can_create_discount_on_category(): void
    {
        $this->actingAsAdmin();

        $category = Category::factory()->create();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'category',
            'discountable_id' => $category->id,
            'type' => 'fixed',
            'value' => 50000,
        ]);

        $response->assertStatus(201)->assertJsonPath('discount.discountable_type', 'category');
    }

    public function test_admin_can_create_discount_on_brand(): void
    {
        $this->actingAsAdmin();

        $brand = Brand::factory()->create();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'brand',
            'discountable_id' => $brand->id,
            'type' => 'percentage',
            'value' => 10,
        ]);

        $response->assertStatus(201)->assertJsonPath('discount.discountable_type', 'brand');
    }

    public function test_store_fails_with_invalid_discountable_type(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'user', // نوع غیرمجاز
            'discountable_id' => 1,
            'type' => 'percentage',
            'value' => 10,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('discountable_type');
    }

    public function test_store_fails_with_nonexistent_discountable_id(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'product',
            'discountable_id' => 999999,
            'type' => 'percentage',
            'value' => 10,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('discountable_id');
    }

    public function test_store_fails_with_percentage_over_100(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => 'percentage',
            'value' => 150,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('value');
    }

    public function test_store_fails_when_ends_at_before_starts_at(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->postJson('/api/admin/discounts', [
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->addDays(5)->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_admin_can_filter_discounts_by_type(): void
    {
        $this->actingAsAdmin();

        Discount::factory()->create(['discountable_type' => 'product', 'discountable_id' => Product::factory()->create()->id]);
        Discount::factory()->create(['discountable_type' => 'brand', 'discountable_id' => Brand::factory()->create()->id]);

        $response = $this->getJson('/api/admin/discounts?discountable_type=brand');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ------------------------------------------------------------------
    // update / destroy
    // ------------------------------------------------------------------

    public function test_admin_can_update_discount(): void
    {
        $this->actingAsAdmin();

        $discount = Discount::factory()->create(['value' => 10]);

        $response = $this->putJson("/api/admin/discounts/{$discount->id}", [
            'value' => 30,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('discount.value', 30)
            ->assertJsonPath('discount.is_active', false);
    }

    public function test_admin_can_delete_discount(): void
    {
        $this->actingAsAdmin();

        $discount = Discount::factory()->create();

        $this->deleteJson("/api/admin/discounts/{$discount->id}")->assertStatus(200);

        $this->assertDatabaseMissing('discounts', ['id' => $discount->id]);
    }
}
