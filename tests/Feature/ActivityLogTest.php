<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ActivityLogTest extends TestCase
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
    // ثبت خودکار created/updated/deleted روی مدل‌ها
    // ------------------------------------------------------------------

    public function test_creating_product_logs_activity_with_user(): void
    {
        $admin = $this->actingAsAdmin();

        $product = Product::factory()->create();

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => 'product',
            'loggable_id' => $product->id,
            'action' => ActivityLog::ACTION_CREATED,
            'user_id' => $admin->id,
        ]);
    }

    public function test_activity_log_user_id_is_null_without_authenticated_user(): void
    {
        // بدون Passport::actingAs - یعنی هیچ کاربری لاگین نیست
        $product = Product::factory()->create();

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => 'product',
            'loggable_id' => $product->id,
            'action' => ActivityLog::ACTION_CREATED,
            'user_id' => null,
        ]);
    }

    public function test_updating_product_logs_before_and_after_values(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create(['price' => 100000]);
        $product->update(['price' => 150000]);

        $log = ActivityLog::where('loggable_type', 'product')
            ->where('loggable_id', $product->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(100000, $log->changes['before']['price']);
        $this->assertEquals(150000, $log->changes['after']['price']);
    }

    public function test_deleting_product_logs_activity(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $productId = $product->id;

        $product->delete();

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => 'product',
            'loggable_id' => $productId,
            'action' => ActivityLog::ACTION_DELETED,
        ]);
    }

    public function test_creating_category_and_brand_logs_activity(): void
    {
        $this->actingAsAdmin();

        $category = Category::factory()->create();
        $brand = Brand::factory()->create();

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => 'category',
            'loggable_id' => $category->id,
            'action' => ActivityLog::ACTION_CREATED,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => 'brand',
            'loggable_id' => $brand->id,
            'action' => ActivityLog::ACTION_CREATED,
        ]);
    }

    // ------------------------------------------------------------------
    // استثنای status سفارش (چون order_status_histories جدا لاگش می‌کنه)
    // ------------------------------------------------------------------

    public function test_order_status_only_change_does_not_create_duplicate_activity_log(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);

        // پاک کردن لاگ‌های مربوط به created، تا فقط رفتار updated رو بسنجیم
        ActivityLog::where('loggable_type', 'order')->where('loggable_id', $order->id)->delete();

        $order->transitionTo(Order::STATUS_AWAITING_PAYMENT);

        $updatedLogsCount = ActivityLog::where('loggable_type', 'order')
            ->where('loggable_id', $order->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->count();

        $this->assertEquals(0, $updatedLogsCount);

        // ولی order_status_histories باید طبیعتاً ثبت شده باشه (مسئولیت جدا)
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_AWAITING_PAYMENT,
        ]);
    }

    public function test_order_non_status_field_change_does_create_activity_log(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create();

        $order->update(['admin_note' => 'یادداشت تستی']);

        $log = ActivityLog::where('loggable_type', 'order')
            ->where('loggable_id', $order->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('یادداشت تستی', $log->changes['after']['admin_note']);
    }

    public function test_order_update_with_status_and_other_field_together_still_logs(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create(['status' => Order::STATUS_PENDING_REVIEW]);

        ActivityLog::where('loggable_type', 'order')->where('loggable_id', $order->id)->delete();

        // آپدیت هم‌زمان status و admin_note - چون فقط status نیست، باید لاگ بشه
        $order->update(['status' => Order::STATUS_CANCELLED, 'admin_note' => 'لغو دستی']);

        $log = ActivityLog::where('loggable_type', 'order')
            ->where('loggable_id', $order->id)
            ->where('action', ActivityLog::ACTION_UPDATED)
            ->first();

        $this->assertNotNull($log);
    }

    // ------------------------------------------------------------------
    // ActivityLogController
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_activity_logs(): void
    {
        $this->getJson('/api/admin/activity-logs')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_activity_logs(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/activity-logs')->assertStatus(403);
    }

    public function test_admin_can_list_activity_logs(): void
    {
        $this->actingAsAdmin();

        Product::factory()->create();

        $response = $this->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_admin_can_filter_activity_logs_by_loggable_type(): void
    {
        $this->actingAsAdmin();

        Product::factory()->create();
        Category::factory()->create();

        $response = $this->getJson('/api/admin/activity-logs?loggable_type=category');

        $response->assertStatus(200);

        $types = collect($response->json('data'))->pluck('loggable_type')->unique();
        $this->assertEquals(['category'], $types->values()->all());
    }

    public function test_admin_can_filter_activity_logs_by_action(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $product->update(['price' => 999999]);

        $response = $this->getJson('/api/admin/activity-logs?action=updated&loggable_type=product');

        $response->assertStatus(200);

        $actions = collect($response->json('data'))->pluck('action')->unique();
        $this->assertEquals(['updated'], $actions->values()->all());
    }

    public function test_admin_can_filter_activity_logs_by_user(): void
    {
        $admin = $this->actingAsAdmin();

        Product::factory()->create();

        $response = $this->getJson("/api/admin/activity-logs?user_id={$admin->id}");

        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json('data')));

        $userIds = collect($response->json('data'))->pluck('user_id')->unique();
        $this->assertEquals([$admin->id], $userIds->values()->all());
    }
}
