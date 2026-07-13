<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CouponControllerTest extends TestCase
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
    // دسترسی
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_coupons(): void
    {
        $this->getJson('/api/admin/coupons')->assertStatus(401);
    }

    public function test_non_admin_cannot_manage_coupons(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->postJson('/api/admin/coupons', [])->assertStatus(403);
    }

    public function test_warehouse_can_manage_coupons(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/coupons')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_percentage_coupon(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/coupons', [
            'code' => 'summer10',
            'type' => 'percentage',
            'value' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('coupon.code', 'SUMMER10') // باید بزرگ‌حرف ذخیره بشه
            ->assertJsonPath('coupon.value', 10);
    }

    public function test_admin_can_create_fixed_coupon_with_limits(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/coupons', [
            'code' => 'FIXED50K',
            'type' => 'fixed',
            'value' => 50000,
            'min_cart_amount' => 200000,
            'usage_limit' => 100,
            'usage_limit_per_user' => 1,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('coupon.min_cart_amount', 200000)
            ->assertJsonPath('coupon.usage_limit', 100);
    }

    public function test_store_fails_with_duplicate_code_case_insensitive(): void
    {
        $this->actingAsAdmin();

        Coupon::factory()->create(['code' => 'SUMMER10']);

        $response = $this->postJson('/api/admin/coupons', [
            'code' => 'summer10', // همون کد، فقط با حروف کوچیک
            'type' => 'percentage',
            'value' => 15,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_store_fails_with_percentage_over_100(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/coupons', [
            'code' => 'BIG100',
            'type' => 'percentage',
            'value' => 150,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('value');
    }

    public function test_store_fails_when_ends_at_before_starts_at(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/coupons', [
            'code' => 'BADDATE',
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->addDays(5)->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    // ------------------------------------------------------------------
    // index / update / destroy
    // ------------------------------------------------------------------

    public function test_admin_can_filter_coupons_by_active_status(): void
    {
        $this->actingAsAdmin();

        Coupon::factory()->create(['is_active' => true]);
        Coupon::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/admin/coupons?is_active=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_update_coupon(): void
    {
        $this->actingAsAdmin();

        $coupon = Coupon::factory()->create(['value' => 10]);

        $response = $this->putJson("/api/admin/coupons/{$coupon->id}", [
            'value' => 20,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('coupon.value', 20)
            ->assertJsonPath('coupon.is_active', false);
    }

    public function test_admin_can_delete_coupon(): void
    {
        $this->actingAsAdmin();

        $coupon = Coupon::factory()->create();

        $this->deleteJson("/api/admin/coupons/{$coupon->id}")->assertStatus(200);

        $this->assertDatabaseMissing('coupons', ['id' => $coupon->id]);
    }
}
