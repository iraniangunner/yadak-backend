<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    private function createPaidOrder(User $customer, int $totalAmount, array $items): Order
    {
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
            'total_amount' => $totalAmount,
        ]);

        foreach ($items as $item) {
            $order->items()->create($item);
        }

        return $order;
    }

    // ------------------------------------------------------------------
    // دسترسی
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_reports(): void
    {
        $this->getJson('/api/admin/reports/product-sales')->assertStatus(401);
    }

    public function test_warehouse_cannot_access_reports(): void
    {
        // گزارش‌گیری فقط برای admin هست، حتی warehouse هم نمی‌تونه
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/reports/product-sales')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // گزارش فروش کالا
    // ------------------------------------------------------------------

    public function test_product_sales_report_aggregates_only_paid_orders(): void
    {
        $this->actingAsAdmin();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->createPaidOrder($customer, 200000, [
            ['title' => 'لنت ترمز', 'sku' => 'SKU-1', 'price' => 100000, 'quantity' => 2],
        ]);

        // سفارش پرداخت‌نشده - نباید توی گزارش بیاد
        $unpaidOrder = Order::factory()->create(['user_id' => $customer->id, 'status' => Order::STATUS_PENDING_REVIEW]);
        $unpaidOrder->items()->create(['title' => 'لنت ترمز', 'sku' => 'SKU-1', 'price' => 100000, 'quantity' => 5]);

        $response = $this->getJson('/api/admin/reports/product-sales');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals(2, $data[0]['total_quantity']);
        $this->assertEquals(200000, $data[0]['total_revenue']);
    }

    public function test_product_sales_report_respects_date_range(): void
    {
        $this->actingAsAdmin();

        $customer = User::factory()->create();

        $this->createPaidOrder($customer, 100000, [
            ['title' => 'کالای قدیمی', 'sku' => 'OLD', 'price' => 100000, 'quantity' => 1],
        ])->update(['paid_at' => now()->subDays(30)]);

        $this->createPaidOrder($customer, 50000, [
            ['title' => 'کالای جدید', 'sku' => 'NEW', 'price' => 50000, 'quantity' => 1],
        ]);

        $response = $this->getJson('/api/admin/reports/product-sales?from=' . now()->subDay()->toDateString());

        $response->assertStatus(200);
        $skus = collect($response->json('data'))->pluck('sku');

        $this->assertTrue($skus->contains('NEW'));
        $this->assertFalse($skus->contains('OLD'));
    }

    public function test_product_sales_report_exports_excel(): void
    {
        Excel::fake();
        $this->actingAsAdmin();

        $customer = User::factory()->create();
        $this->createPaidOrder($customer, 100000, [
            ['title' => 'کالا', 'sku' => 'SKU', 'price' => 100000, 'quantity' => 1],
        ]);

        $response = $this->get('/api/admin/reports/product-sales?format=xlsx');

        $response->assertStatus(200);
        Excel::assertDownloaded('product-sales.xlsx');
    }

    // ------------------------------------------------------------------
    // گزارش فروش مشتری
    // ------------------------------------------------------------------

    public function test_customer_sales_report_aggregates_per_customer(): void
    {
        $this->actingAsAdmin();

        $customerA = User::factory()->create(['name' => 'مشتری الف']);
        $customerB = User::factory()->create(['name' => 'مشتری ب']);

        $this->createPaidOrder($customerA, 100000, [['title' => 'کالا', 'sku' => 'S1', 'price' => 100000, 'quantity' => 1]]);
        $this->createPaidOrder($customerA, 50000, [['title' => 'کالا۲', 'sku' => 'S2', 'price' => 50000, 'quantity' => 1]]);
        $this->createPaidOrder($customerB, 30000, [['title' => 'کالا۳', 'sku' => 'S3', 'price' => 30000, 'quantity' => 1]]);

        $response = $this->getJson('/api/admin/reports/customer-sales');

        $response->assertStatus(200);
        $data = collect($response->json('data'))->keyBy('user_id');

        $this->assertEquals(2, $data[$customerA->id]['total_orders']);
        $this->assertEquals(150000, $data[$customerA->id]['total_spent']);
        $this->assertEquals(1, $data[$customerB->id]['total_orders']);
    }

    public function test_customer_sales_report_exports_excel(): void
    {
        Excel::fake();
        $this->actingAsAdmin();

        $customer = User::factory()->create();
        $this->createPaidOrder($customer, 100000, [['title' => 'کالا', 'sku' => 'S1', 'price' => 100000, 'quantity' => 1]]);

        $this->get('/api/admin/reports/customer-sales?format=xlsx')->assertStatus(200);
        Excel::assertDownloaded('customer-sales.xlsx');
    }

    // ------------------------------------------------------------------
    // گزارش فروش شهر
    // ------------------------------------------------------------------

    public function test_city_sales_report_groups_by_customer_city(): void
    {
        $this->actingAsAdmin();

        $tehranCustomer = User::factory()->create(['city' => 'تهران']);
        $mashhadCustomer = User::factory()->create(['city' => 'مشهد']);

        $this->createPaidOrder($tehranCustomer, 100000, [['title' => 'کالا', 'sku' => 'S1', 'price' => 100000, 'quantity' => 1]]);
        $this->createPaidOrder($tehranCustomer, 50000, [['title' => 'کالا۲', 'sku' => 'S2', 'price' => 50000, 'quantity' => 1]]);
        $this->createPaidOrder($mashhadCustomer, 70000, [['title' => 'کالا۳', 'sku' => 'S3', 'price' => 70000, 'quantity' => 1]]);

        $response = $this->getJson('/api/admin/reports/city-sales');

        $response->assertStatus(200);
        $data = collect($response->json('data'))->keyBy('city');

        $this->assertEquals(150000, $data['تهران']['total_sales']);
        $this->assertEquals(2, $data['تهران']['total_orders']);
        $this->assertEquals(70000, $data['مشهد']['total_sales']);
    }

    public function test_city_sales_report_exports_excel(): void
    {
        Excel::fake();
        $this->actingAsAdmin();

        $customer = User::factory()->create(['city' => 'تهران']);
        $this->createPaidOrder($customer, 100000, [['title' => 'کالا', 'sku' => 'S1', 'price' => 100000, 'quantity' => 1]]);

        $this->get('/api/admin/reports/city-sales?format=xlsx')->assertStatus(200);
        Excel::assertDownloaded('city-sales.xlsx');
    }

    // ------------------------------------------------------------------
    // گزارش مرجوعی
    // ------------------------------------------------------------------

    public function test_returns_report_filters_by_status(): void
    {
        $this->actingAsAdmin();

        OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);
        OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REFUNDED]);

        $response = $this->getJson('/api/admin/reports/returns?status=refunded');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_returns_report_exports_excel(): void
    {
        Excel::fake();
        $this->actingAsAdmin();

        OrderReturn::factory()->create();

        $this->get('/api/admin/reports/returns?format=xlsx')->assertStatus(200);
        Excel::assertDownloaded('returns.xlsx');
    }
}
