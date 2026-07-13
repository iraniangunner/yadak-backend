<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // تا تست‌ها به مقدار .env محیط اجرا وابسته نباشن، صریح تنظیم می‌کنیم.
        config(['services.pricing.rounding_step' => 1000]);
    }

    public function test_product_without_discount_has_final_price_equal_to_price(): void
    {
        $product = Product::factory()->create(['price' => 123456]);

        $this->assertEquals(123456, $product->final_price);
        $this->assertEquals(0, $product->discount_percent);
    }

    public function test_percentage_discount_computes_final_price_with_rounding(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 15,
        ]);

        // 100000 - 15% = 85000، از قبل مضرب ۱۰۰۰ هست پس رُند تغییری نمی‌ده
        $this->assertEquals(85000, $product->fresh()->final_price);
        $this->assertEquals(15, $product->fresh()->discount_percent);
    }

    public function test_fixed_discount_computes_final_price(): void
    {
        $product = Product::factory()->create(['price' => 200000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_FIXED,
            'value' => 35000,
        ]);

        $this->assertEquals(165000, $product->fresh()->final_price);
    }

    public function test_rounding_rounds_down_to_nearest_step(): void
    {
        $product = Product::factory()->create(['price' => 123456]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_FIXED,
            'value' => 456, // 123456 - 456 = 123000 دقیقاً مضرب ۱۰۰۰
        ]);

        $this->assertEquals(123000, $product->fresh()->final_price);
    }

    public function test_rounding_never_rounds_up_above_actual_discounted_price(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_FIXED,
            'value' => 1, // 100000 - 1 = 99999 → باید به 99000 رُند بشه، نه 100000
        ]);

        $this->assertEquals(99000, $product->fresh()->final_price);
    }

    public function test_product_discount_takes_priority_over_category_discount(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['price' => 100000, 'category_id' => $category->id]);

        Discount::factory()->create([
            'discountable_type' => 'category',
            'discountable_id' => $category->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 50,
        ]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
        ]);

        // باید تخفیف محصول (۱۰٪) اعمال بشه، نه تخفیف دسته (۵۰٪)
        $this->assertEquals(90000, $product->fresh()->final_price);
    }

    public function test_category_discount_applies_when_no_direct_discount(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['price' => 100000, 'category_id' => $category->id]);

        Discount::factory()->create([
            'discountable_type' => 'category',
            'discountable_id' => $category->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 20,
        ]);

        $this->assertEquals(80000, $product->fresh()->final_price);
    }

    public function test_category_discount_takes_priority_over_brand_discount(): void
    {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create([
            'price' => 100000,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
        ]);

        Discount::factory()->create([
            'discountable_type' => 'brand',
            'discountable_id' => $brand->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 50,
        ]);

        Discount::factory()->create([
            'discountable_type' => 'category',
            'discountable_id' => $category->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
        ]);

        $this->assertEquals(90000, $product->fresh()->final_price);
    }

    public function test_brand_discount_applies_when_no_direct_or_category_discount(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['price' => 100000, 'brand_id' => $brand->id]);

        Discount::factory()->create([
            'discountable_type' => 'brand',
            'discountable_id' => $brand->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 25,
        ]);

        $this->assertEquals(75000, $product->fresh()->final_price);
    }

    public function test_inactive_discount_is_ignored(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 50,
            'is_active' => false,
        ]);

        $this->assertEquals(100000, $product->fresh()->final_price);
    }

    public function test_expired_discount_is_ignored(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 50,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertEquals(100000, $product->fresh()->final_price);
    }

    public function test_future_discount_is_ignored(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 50,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(10),
        ]);

        $this->assertEquals(100000, $product->fresh()->final_price);
    }

    public function test_currently_active_dated_discount_applies(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 30,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertEquals(70000, $product->fresh()->final_price);
    }

    public function test_discount_percent_for_fixed_type_is_computed_from_price(): void
    {
        $product = Product::factory()->create(['price' => 200000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_FIXED,
            'value' => 50000, // 25% از 200000
        ]);

        $this->assertEquals(25, $product->fresh()->discount_percent);
    }

    public function test_product_appears_in_api_response_with_final_price(): void
    {
        $product = Product::factory()->create(['price' => 100000, 'is_active' => true]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
        ]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('product.final_price', 90000)
            ->assertJsonPath('product.discount_percent', 10);
    }
}
