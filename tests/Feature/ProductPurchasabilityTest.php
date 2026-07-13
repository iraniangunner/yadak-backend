<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPurchasabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_product_is_purchasable(): void
    {
        $product = Product::factory()->create([
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertTrue($product->isPurchasable());
        $this->assertTrue($product->is_purchasable);
    }

    public function test_inactive_product_is_not_purchasable(): void
    {
        $product = Product::factory()->create(['is_active' => false]);

        $this->assertFalse($product->isPurchasable());
    }

    public function test_individually_stopped_product_is_not_purchasable(): void
    {
        $product = Product::factory()->create([
            'is_active' => true,
            'stock_status' => Product::STATUS_STOPPED,
        ]);

        $this->assertFalse($product->isPurchasable());
    }

    public function test_out_of_stock_product_is_not_purchasable(): void
    {
        $product = Product::factory()->create([
            'is_active' => true,
            'stock_status' => Product::STATUS_OUT_OF_STOCK,
        ]);

        $this->assertFalse($product->isPurchasable());
    }

    public function test_product_in_sales_stopped_category_is_not_purchasable(): void
    {
        $category = Category::factory()->create(['sales_stopped' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertFalse($product->fresh()->isPurchasable());
    }

    public function test_product_in_sales_stopped_brand_is_not_purchasable(): void
    {
        $brand = Brand::factory()->create(['sales_stopped' => true]);
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertFalse($product->fresh()->isPurchasable());
    }

    public function test_product_with_normal_category_and_brand_is_purchasable(): void
    {
        $category = Category::factory()->create(['sales_stopped' => false]);
        $brand = Brand::factory()->create(['sales_stopped' => false]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'is_active' => true,
            'stock_status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertTrue($product->fresh()->isPurchasable());
    }

    public function test_is_purchasable_appears_in_api_response(): void
    {
        $category = Category::factory()->create(['sales_stopped' => true]);
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)->assertJsonPath('product.is_purchasable', false);
    }
}
