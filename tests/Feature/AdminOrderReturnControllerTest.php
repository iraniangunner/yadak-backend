<?php

namespace Tests\Feature;

use App\Models\OrderReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminOrderReturnControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_access_admin_returns(): void
    {
        $this->getJson('/api/admin/returns')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_admin_returns(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/admin/returns')->assertStatus(403);
    }

    public function test_warehouse_can_access_admin_returns(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/returns')->assertStatus(200);
    }

    public function test_admin_can_filter_returns_by_status(): void
    {
        $this->actingAsAdmin();

        OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);
        OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REFUNDED]);

        $response = $this->getJson('/api/admin/returns?status=requested');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_approve_requested_return(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/approve");

        $response->assertStatus(200)->assertJsonPath('return.status', OrderReturn::STATUS_APPROVED);
    }

    public function test_approve_fails_if_not_requested(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_APPROVED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/approve");

        $response->assertStatus(422);
    }

    public function test_admin_can_reject_requested_return_with_note(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/reject", [
            'admin_note' => 'کالا استفاده‌شده بود',
        ]);

        $response->assertStatus(200)->assertJsonPath('return.status', OrderReturn::STATUS_REJECTED);
    }

    public function test_reject_fails_without_admin_note(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/reject", []);

        $response->assertStatus(422)->assertJsonValidationErrors('admin_note');
    }

    public function test_admin_can_mark_approved_return_as_refunded(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_APPROVED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/mark-refunded", [
            'refund_amount' => 45000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('return.status', OrderReturn::STATUS_REFUNDED)
            ->assertJsonPath('return.refund_amount', 45000);
    }

    public function test_mark_refunded_fails_if_not_approved(): void
    {
        $this->actingAsAdmin();

        $return = OrderReturn::factory()->create(['status' => OrderReturn::STATUS_REQUESTED]);

        $response = $this->postJson("/api/admin/returns/{$return->id}/mark-refunded", [
            'refund_amount' => 10000,
        ]);

        $response->assertStatus(422);
    }
}
