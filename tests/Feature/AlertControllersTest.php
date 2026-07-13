<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockSubscription;
use App\Models\SalesAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AlertControllersTest extends TestCase
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
    // SalesAlertController
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_sales_alerts(): void
    {
        $this->getJson('/api/admin/sales-alerts')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_sales_alerts(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/admin/sales-alerts')->assertStatus(403);
    }

    public function test_warehouse_can_view_sales_alerts(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/sales-alerts')->assertStatus(200);
    }

    public function test_admin_can_list_sales_alerts(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        SalesAlert::create([
            'product_id' => $product->id,
            'average_quantity' => 2.5,
            'actual_quantity' => 10,
            'tolerance_percent' => 50,
            'period_days' => 7,
        ]);

        $response = $this->getJson('/api/admin/sales-alerts');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_filter_sales_alerts_by_product(): void
    {
        $this->actingAsAdmin();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        SalesAlert::create(['product_id' => $productA->id, 'average_quantity' => 1, 'actual_quantity' => 5, 'tolerance_percent' => 50, 'period_days' => 7]);
        SalesAlert::create(['product_id' => $productB->id, 'average_quantity' => 1, 'actual_quantity' => 5, 'tolerance_percent' => 50, 'period_days' => 7]);

        $response = $this->getJson("/api/admin/sales-alerts?product_id={$productA->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ------------------------------------------------------------------
    // InventoryAlertController
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_inventory_alerts(): void
    {
        $this->getJson('/api/admin/inventory-alerts')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_inventory_alerts(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/admin/inventory-alerts')->assertStatus(403);
    }

    public function test_lists_low_stock_products_sorted_by_waiting_customers(): void
    {
        $this->actingAsAdmin();

        $lowDemand = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);
        $highDemand = Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);
        $notRelevant = Product::factory()->create(['stock_status' => Product::STATUS_AVAILABLE]);

        ProductStockSubscription::create(['product_id' => $lowDemand->id, 'mobile' => '09120000001']);
        ProductStockSubscription::create(['product_id' => $highDemand->id, 'mobile' => '09120000002']);
        ProductStockSubscription::create(['product_id' => $highDemand->id, 'mobile' => '09120000003']);

        $response = $this->getJson('/api/admin/inventory-alerts');

        $response->assertStatus(200);
        $products = $response->json('low_stock_products');

        $this->assertCount(2, $products);
        $this->assertEquals($highDemand->id, $products[0]['id']); // بیشترین تقاضا اول
        $this->assertEquals(2, $products[0]['waiting_customers_count']);

        $notRelevantIds = collect($products)->pluck('id');
        $this->assertFalse($notRelevantIds->contains($notRelevant->id));
    }

    public function test_lists_stale_pending_orders(): void
    {
        $this->actingAsAdmin();

        $staleOrder = Order::factory()->create([
            'status' => Order::STATUS_PENDING_REVIEW,
            'created_at' => now()->subHours(48),
        ]);

        $freshOrder = Order::factory()->create([
            'status' => Order::STATUS_PENDING_REVIEW,
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->getJson('/api/admin/inventory-alerts');

        $response->assertStatus(200);
        $orderIds = collect($response->json('stale_pending_orders'))->pluck('id');

        $this->assertTrue($orderIds->contains($staleOrder->id));
        $this->assertFalse($orderIds->contains($freshOrder->id));
    }

    public function test_respects_custom_stale_hours_threshold(): void
    {
        config(['services.inventory_alert.stale_order_hours' => 5]);

        $this->actingAsAdmin();

        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING_REVIEW,
            'created_at' => now()->subHours(6),
        ]);

        $response = $this->getJson('/api/admin/inventory-alerts');

        $response->assertStatus(200)->assertJsonPath('summary.stale_order_threshold_hours', 5);

        $orderIds = collect($response->json('stale_pending_orders'))->pluck('id');
        $this->assertTrue($orderIds->contains($order->id));
    }

    public function test_summary_counts_are_correct(): void
    {
        $this->actingAsAdmin();

        Product::factory()->create(['stock_status' => Product::STATUS_OUT_OF_STOCK]);
        Product::factory()->create(['stock_status' => Product::STATUS_STOPPED]);

        Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW, 'created_at' => now()->subHours(48)]);

        $response = $this->getJson('/api/admin/inventory-alerts');

        $response->assertStatus(200)
            ->assertJsonPath('summary.low_stock_count', 2)
            ->assertJsonPath('summary.stale_orders_count', 1);
    }
}
