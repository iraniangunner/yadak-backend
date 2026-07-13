<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_subscribe_with_mobile_to_out_of_stock_product(): void
    {
        $product = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);

        $response = $this->postJson("/api/products/{$product->id}/stock-subscribe", [
            'mobile' => '09121234567',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('product_stock_subscriptions', [
            'product_id' => $product->id,
            'mobile' => '09121234567',
            'user_id' => null,
        ]);
    }

    public function test_subscribe_fails_without_mobile(): void
    {
        $product = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);

        $response = $this->postJson("/api/products/{$product->id}/stock-subscribe", []);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_subscribe_fails_with_invalid_mobile_format(): void
    {
        $product = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);

        $response = $this->postJson("/api/products/{$product->id}/stock-subscribe", [
            'mobile' => '12345',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    public function test_subscribe_fails_if_product_already_available(): void
    {
        $product = Product::factory()->create(['stock_status' => Product::STATUS_AVAILABLE]);

        $response = $this->postJson("/api/products/{$product->id}/stock-subscribe", [
            'mobile' => '09121234567',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_subscribe_twice_with_same_mobile(): void
    {
        $product = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);

        $this->postJson("/api/products/{$product->id}/stock-subscribe", ['mobile' => '09121234567'])
            ->assertStatus(201);

        $response = $this->postJson("/api/products/{$product->id}/stock-subscribe", ['mobile' => '09121234567']);

        $response->assertStatus(422);

        $this->assertDatabaseCount('product_stock_subscriptions', 1);
    }
}
