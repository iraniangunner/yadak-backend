<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceTierPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pricing.rounding_step' => 1000]);
    }

    public function test_product_without_tiers_uses_base_price_for_any_quantity(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        $this->assertEquals(100000, $product->priceForQuantity(1));
        $this->assertEquals(100000, $product->priceForQuantity(10));
    }

    public function test_quantity_within_tier_range_uses_tier_price(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        $product->priceTiers()->create(['min_quantity' => 1, 'max_quantity' => 3, 'price' => 100000]);
        $product->priceTiers()->create(['min_quantity' => 4, 'max_quantity' => null, 'price' => 90000]);

        $this->assertEquals(100000, $product->priceForQuantity(2));
        $this->assertEquals(90000, $product->priceForQuantity(4));
        $this->assertEquals(90000, $product->priceForQuantity(100)); // بازه‌ی باز (به بالا)
    }

    public function test_quantity_not_covered_by_any_tier_falls_back_to_base_price(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        // فقط بازه‌ی ۵ تا ۱۰ تعریف شده؛ تعداد ۲ توی هیچ بازه‌ای نیست
        $product->priceTiers()->create(['min_quantity' => 5, 'max_quantity' => 10, 'price' => 80000]);

        $this->assertEquals(100000, $product->priceForQuantity(2));
        $this->assertEquals(80000, $product->priceForQuantity(7));
    }

    public function test_price_for_quantity_applies_discount_on_top_of_tier_price(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        $product->priceTiers()->create(['min_quantity' => 4, 'max_quantity' => null, 'price' => 80000]);

        Discount::factory()->create([
            'discountable_type' => 'product',
            'discountable_id' => $product->id,
            'type' => Discount::TYPE_PERCENTAGE,
            'value' => 10,
        ]);

        // قیمت پلکانی (۸۰۰۰۰) - ۱۰٪ تخفیف = ۷۲۰۰۰
        $this->assertEquals(72000, $product->fresh()->priceForQuantity(5));
    }

    public function test_price_for_quantity_endpoint_returns_correct_unit_price_and_total(): void
    {
        $product = Product::factory()->create(['price' => 100000]);

        $product->priceTiers()->create(['min_quantity' => 4, 'max_quantity' => null, 'price' => 90000]);

        $response = $this->getJson("/api/products/{$product->id}/price-for-quantity?quantity=5");

        $response->assertStatus(200)
            ->assertJsonPath('quantity', 5)
            ->assertJsonPath('unit_price', 90000)
            ->assertJsonPath('total', 450000);
    }

    public function test_price_for_quantity_endpoint_fails_without_quantity(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}/price-for-quantity");

        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_price_for_quantity_endpoint_fails_with_zero_quantity(): void
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}/price-for-quantity?quantity=0");

        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
    }
}
