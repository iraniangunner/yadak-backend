<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingOptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * فیک کردن هر سه provider واقعی (اسنپ‌باکس، تیپاکس، پست پیشتاز)
     * با پاسخ‌های موفق پیش‌فرض، تا کنترلر بدون خطا جواب بده.
     */
    private function fakeAllProviders(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.snappbox.ir/*' => Http::response([
                'services' => [['title' => 'اکسپرس', 'fee' => 25000, 'eta_hours' => 3]],
            ], 200),
            'api.tipax.ir/*' => Http::response([
                'data' => [['service_name' => 'اکسپرس', 'price' => 35000, 'estimated_days' => 1]],
            ], 200),
            'api.post.ir/*' => Http::response([
                'price' => 18000,
                'delivery_days' => 4,
            ], 200),
        ], $overrides));
    }

    public function test_returns_options_from_all_three_providers(): void
    {
        $this->fakeAllProviders();

        $product = Product::factory()->create(['weight_kg' => 2]);

        $response = $this->postJson('/api/shipping/options', [
            'city' => 'تهران',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(200);
        $options = $response->json('options');

        $this->assertCount(3, $options);

        $carriers = collect($options)->pluck('carrier');
        $this->assertTrue($carriers->contains('اسنپ‌باکس'));
        $this->assertTrue($carriers->contains('تیپاکس'));
        $this->assertTrue($carriers->contains('پست'));
    }

    public function test_option_costs_match_provider_responses(): void
    {
        $this->fakeAllProviders([
            'api.snappbox.ir/*' => Http::response([
                'services' => [['title' => 'استاندارد', 'fee' => 22000, 'eta_hours' => 5]],
            ], 200),
        ]);

        $product = Product::factory()->create(['weight_kg' => 1]);

        $response = $this->postJson('/api/shipping/options', [
            'city' => 'تهران',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $options = collect($response->json('options'))->keyBy('carrier');

        $this->assertEquals(22000, $options['اسنپ‌باکس']['cost']);
        $this->assertEquals(35000, $options['تیپاکس']['cost']);
        $this->assertEquals(18000, $options['پست']['cost']);
    }

    public function test_validation_fails_without_city_or_items(): void
    {
        $response = $this->postJson('/api/shipping/options', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['city', 'items']);
    }

    public function test_weight_is_summed_across_items(): void
    {
        $this->fakeAllProviders();

        $productA = Product::factory()->create(['weight_kg' => 1]);
        $productB = Product::factory()->create(['weight_kg' => 3]);

        $response = $this->postJson('/api/shipping/options', [
            'city' => 'تهران',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 2], // 2kg
                ['product_id' => $productB->id, 'quantity' => 1], // 3kg
            ],
        ]);

        $response->assertStatus(200)->assertJsonPath('total_weight_kg', 5);
    }

    public function test_options_still_returned_when_one_provider_fails(): void
    {
        $this->fakeAllProviders([
            'api.snappbox.ir/*' => Http::response(['message' => 'down'], 500),
        ]);

        $product = Product::factory()->create(['weight_kg' => 1]);

        $response = $this->postJson('/api/shipping/options', [
            'city' => 'تهران',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(200);
        $options = $response->json('options');

        $this->assertCount(2, $options); // فقط تیپاکس و پست
    }
}