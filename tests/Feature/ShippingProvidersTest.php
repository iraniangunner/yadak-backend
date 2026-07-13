<?php

namespace Tests\Feature;

use App\Services\Shipping\AggregateShippingProvider;
use App\Services\Shipping\PostPishtazShippingProvider;
use App\Services\Shipping\SnappBoxShippingProvider;
use App\Services\Shipping\TipaxShippingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingProvidersTest extends TestCase
{
    // ------------------------------------------------------------------
    // SnappBoxShippingProvider
    // ------------------------------------------------------------------

    public function test_snappbox_maps_successful_response_correctly(): void
    {
        Http::fake([
            'api.snappbox.ir/*' => Http::response([
                'services' => [
                    ['title' => 'اکسپرس', 'fee' => 25000, 'eta_hours' => 3],
                ],
            ], 200),
        ]);

        $options = app(SnappBoxShippingProvider::class)->getOptions('تهران', 2);

        $this->assertCount(1, $options);
        $this->assertEquals('اسنپ‌باکس', $options[0]['carrier']);
        $this->assertEquals('اکسپرس', $options[0]['service_name']);
        $this->assertEquals(25000, $options[0]['cost']);
    }

    public function test_snappbox_returns_empty_array_on_error_response(): void
    {
        Http::fake([
            'api.snappbox.ir/*' => Http::response(['message' => 'error'], 500),
        ]);

        $options = app(SnappBoxShippingProvider::class)->getOptions('تهران', 2);

        $this->assertEquals([], $options);
    }

    public function test_snappbox_returns_empty_array_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $options = app(SnappBoxShippingProvider::class)->getOptions('تهران', 2);

        $this->assertEquals([], $options);
    }

    // ------------------------------------------------------------------
    // TipaxShippingProvider
    // ------------------------------------------------------------------

    public function test_tipax_maps_successful_response_correctly(): void
    {
        Http::fake([
            'api.tipax.ir/*' => Http::response([
                'data' => [
                    ['service_name' => 'اکسپرس', 'price' => 35000, 'estimated_days' => 1],
                    ['service_name' => 'عادی', 'price' => 20000, 'estimated_days' => 3],
                ],
            ], 200),
        ]);

        $options = app(TipaxShippingProvider::class)->getOptions('مشهد', 1.5);

        $this->assertCount(2, $options);
        $this->assertEquals('تیپاکس', $options[0]['carrier']);
        $this->assertEquals(35000, $options[0]['cost']);
        $this->assertEquals(1, $options[0]['eta_days']);
    }

    public function test_tipax_returns_empty_array_on_error_response(): void
    {
        Http::fake([
            'api.tipax.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $options = app(TipaxShippingProvider::class)->getOptions('مشهد', 1);

        $this->assertEquals([], $options);
    }

    public function test_tipax_returns_empty_array_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $options = app(TipaxShippingProvider::class)->getOptions('مشهد', 1);

        $this->assertEquals([], $options);
    }

    // ------------------------------------------------------------------
    // PostPishtazShippingProvider
    // ------------------------------------------------------------------

    public function test_post_pishtaz_maps_successful_response_correctly(): void
    {
        Http::fake([
            'api.post.ir/*' => Http::response([
                'price' => 18000,
                'delivery_days' => 4,
            ], 200),
        ]);

        $options = app(PostPishtazShippingProvider::class)->getOptions('اصفهان', 3);

        $this->assertCount(1, $options);
        $this->assertEquals('پست', $options[0]['carrier']);
        $this->assertEquals('پیشتاز', $options[0]['service_name']);
        $this->assertEquals(18000, $options[0]['cost']);
        $this->assertEquals(4, $options[0]['eta_days']);
    }

    public function test_post_pishtaz_returns_empty_array_on_error_response(): void
    {
        Http::fake([
            'api.post.ir/*' => Http::response(['message' => 'error'], 503),
        ]);

        $options = app(PostPishtazShippingProvider::class)->getOptions('اصفهان', 3);

        $this->assertEquals([], $options);
    }

    public function test_post_pishtaz_returns_empty_array_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $options = app(PostPishtazShippingProvider::class)->getOptions('اصفهان', 3);

        $this->assertEquals([], $options);
    }

    // ------------------------------------------------------------------
    // AggregateShippingProvider
    // ------------------------------------------------------------------

    public function test_aggregate_merges_options_from_all_three_providers(): void
    {
        Http::fake([
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
        ]);

        $options = app(AggregateShippingProvider::class)->getOptions('تهران', 2);

        $this->assertCount(3, $options);

        $carriers = collect($options)->pluck('carrier');
        $this->assertTrue($carriers->contains('اسنپ‌باکس'));
        $this->assertTrue($carriers->contains('تیپاکس'));
        $this->assertTrue($carriers->contains('پست'));
    }

    public function test_aggregate_continues_when_one_provider_fails(): void
    {
        Http::fake([
            'api.snappbox.ir/*' => Http::response(['message' => 'down'], 500),
            'api.tipax.ir/*' => Http::response([
                'data' => [['service_name' => 'اکسپرس', 'price' => 35000, 'estimated_days' => 1]],
            ], 200),
            'api.post.ir/*' => Http::response([
                'price' => 18000,
                'delivery_days' => 4,
            ], 200),
        ]);

        $options = app(AggregateShippingProvider::class)->getOptions('تهران', 2);

        // اسنپ‌باکس خطا داد، ولی تیپاکس و پست باید نتیجه بدن
        $this->assertCount(2, $options);

        $carriers = collect($options)->pluck('carrier');
        $this->assertFalse($carriers->contains('اسنپ‌باکس'));
        $this->assertTrue($carriers->contains('تیپاکس'));
        $this->assertTrue($carriers->contains('پست'));
    }

    public function test_aggregate_returns_empty_when_all_providers_fail(): void
    {
        Http::fake(function () {
            throw new ConnectionException('all down');
        });

        $options = app(AggregateShippingProvider::class)->getOptions('تهران', 2);

        $this->assertEquals([], $options);
    }
}
