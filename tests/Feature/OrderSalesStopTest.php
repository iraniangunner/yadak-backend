<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderSalesStopTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    public function test_order_fails_for_individually_stopped_product(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create([
            'is_active' => true,
            'stock_status' => Product::STATUS_STOPPED,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_for_out_of_stock_product(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create([
            'is_active' => true,
            'stock_status' => Product::STATUS_OUT_OF_STOCK,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_for_product_in_sales_stopped_category(): void
    {
        $this->actingAsCustomer();

        $category = Category::factory()->create(['sales_stopped' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_for_product_in_sales_stopped_brand(): void
    {
        $this->actingAsCustomer();

        $brand = Brand::factory()->create(['sales_stopped' => true]);
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_succeeds_for_normal_purchasable_product(): void
    {
        $this->actingAsCustomer();

        $category = Category::factory()->create(['sales_stopped' => false]);
        $brand = Brand::factory()->create(['sales_stopped' => false]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
            'price' => 50000,
        ]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_fails_if_one_of_multiple_items_is_stopped(): void
    {
        $this->actingAsCustomer();

        $normalProduct = Product::factory()->create(['is_active' => true, 'stock_status' => Product::STATUS_AVAILABLE]);
        $stoppedProduct = Product::factory()->create(['is_active' => true, 'stock_status' => Product::STATUS_STOPPED]);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $normalProduct->id, 'quantity' => 1],
                ['product_id' => $stoppedProduct->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }
}
