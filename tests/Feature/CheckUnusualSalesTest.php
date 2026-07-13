<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\SalesAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckUnusualSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sales_alert.period_days' => 7,
            'services.sales_alert.tolerance_percent' => 50,
        ]);
    }

    private function createPaidOrderWithQuantity(Product $product, int $quantity, \Illuminate\Support\Carbon $paidAt): Order
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PAID,
            'paid_at' => $paidAt,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'title' => $product->title,
            'sku' => $product->sku,
            'price' => $product->price,
            'quantity' => $quantity,
        ]);

        return $order;
    }

    public function test_creates_alert_when_sales_exceed_tolerance(): void
    {
        $product = Product::factory()->create();

        // میانگین مبنا: ۷ عدد توی ۷ روز = میانگین روزانه ۱
        $this->createPaidOrderWithQuantity($product, 7, now()->subDays(3));

        // امروز: ۵ عدد فروخته شده (خیلی بیشتر از حد آستانه‌ی ۱.۵)
        $this->createPaidOrderWithQuantity($product, 5, now());

        $this->artisan('sales:check-unusual')->assertExitCode(0);

        $this->assertDatabaseHas('sales_alerts', [
            'product_id' => $product->id,
            'actual_quantity' => 5,
        ]);

        $alert = SalesAlert::where('product_id', $product->id)->first();
        $this->assertEquals(1.0, $alert->average_quantity);
    }

    public function test_does_not_create_alert_within_tolerance(): void
    {
        $product = Product::factory()->create();

        // میانگین روزانه: ۱۴/۷ = ۲
        $this->createPaidOrderWithQuantity($product, 14, now()->subDays(3));

        // امروز: ۲ عدد - دقیقاً برابر میانگین، خیلی کمتر از آستانه (۳)
        $this->createPaidOrderWithQuantity($product, 2, now());

        $this->artisan('sales:check-unusual');

        $this->assertDatabaseCount('sales_alerts', 0);
    }

    public function test_skips_product_with_no_baseline_sales(): void
    {
        $product = Product::factory()->create();

        // هیچ فروشی توی بازه‌ی مبنا نبوده، فقط امروز
        $this->createPaidOrderWithQuantity($product, 10, now());

        $this->artisan('sales:check-unusual');

        $this->assertDatabaseCount('sales_alerts', 0);
    }

    public function test_respects_custom_tolerance_percent(): void
    {
        config(['services.sales_alert.tolerance_percent' => 10]);

        $product = Product::factory()->create();

        // میانگین روزانه: ۷۰/۷ = ۱۰، آستانه با تلورانس ۱۰٪ = ۱۱
        $this->createPaidOrderWithQuantity($product, 70, now()->subDays(3));
        $this->createPaidOrderWithQuantity($product, 12, now());

        $this->artisan('sales:check-unusual');

        $this->assertDatabaseHas('sales_alerts', [
            'product_id' => $product->id,
            'tolerance_percent' => 10,
        ]);
    }

    public function test_baseline_outside_period_is_ignored(): void
    {
        $product = Product::factory()->create();

        // این فروش خیلی قدیمی‌تر از بازه‌ی مبناست (۷ روزه)، نباید توی
        // میانگین حساب بشه
        $this->createPaidOrderWithQuantity($product, 100, now()->subDays(20));

        $this->createPaidOrderWithQuantity($product, 5, now());

        $this->artisan('sales:check-unusual');

        // چون هیچ فروشی توی بازه‌ی مبنای واقعی نبوده، میانگین صفره و نباید هشدار بده
        $this->assertDatabaseCount('sales_alerts', 0);
    }

    public function test_sends_sms_notification_when_alert_triggered(): void
    {
        Http::fake();

        config(['services.admin_mobile' => '09121111111']);

        $product = Product::factory()->create(['title' => 'لنت ترمز جلو']);

        $this->createPaidOrderWithQuantity($product, 7, now()->subDays(3));
        $this->createPaidOrderWithQuantity($product, 5, now());

        $this->artisan('sales:check-unusual');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.kavenegar.com')
                && str_contains($request->url(), 'sms/send.json');
        });
    }
}
