<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ReferralCode;
use App\Models\ReferralCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReferralCommissionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    private function createCommission(string $status, ?int $amount = null): ReferralCommission
    {
        $seller = User::factory()->create();
        $referralCode = ReferralCode::factory()->create(['user_id' => $seller->id]);
        $order = Order::factory()->create();

        return ReferralCommission::create([
            'referral_code_id' => $referralCode->id,
            'order_id' => $order->id,
            'user_id' => $seller->id,
            'commission_amount' => $amount,
            'status' => $status,
        ]);
    }

    public function test_guest_cannot_access_commissions(): void
    {
        $this->getJson('/api/admin/referral-commissions')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_commissions(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/admin/referral-commissions')->assertStatus(403);
    }

    public function test_admin_can_list_commissions_filtered_by_status(): void
    {
        $this->actingAsAdmin();

        $this->createCommission(ReferralCommission::STATUS_PENDING);
        $this->createCommission(ReferralCommission::STATUS_APPROVED, 10000);

        $response = $this->getJson('/api/admin/referral-commissions?status=approved');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_filter_commissions_by_user(): void
    {
        $this->actingAsAdmin();

        $commissionA = $this->createCommission(ReferralCommission::STATUS_APPROVED, 10000);
        $this->createCommission(ReferralCommission::STATUS_APPROVED, 20000);

        $response = $this->getJson("/api/admin/referral-commissions?user_id={$commissionA->user_id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_mark_approved_commission_as_paid(): void
    {
        $this->actingAsAdmin();

        $commission = $this->createCommission(ReferralCommission::STATUS_APPROVED, 10000);

        $response = $this->postJson("/api/admin/referral-commissions/{$commission->id}/mark-paid");

        $response->assertStatus(200)->assertJsonPath('commission.status', ReferralCommission::STATUS_PAID);
    }

    public function test_cannot_mark_pending_commission_as_paid(): void
    {
        $this->actingAsAdmin();

        $commission = $this->createCommission(ReferralCommission::STATUS_PENDING);

        $response = $this->postJson("/api/admin/referral-commissions/{$commission->id}/mark-paid");

        $response->assertStatus(422);
    }

    public function test_cannot_mark_already_paid_commission_as_paid_again(): void
    {
        $this->actingAsAdmin();

        $commission = $this->createCommission(ReferralCommission::STATUS_PAID, 10000);

        $response = $this->postJson("/api/admin/referral-commissions/{$commission->id}/mark-paid");

        $response->assertStatus(422);
    }
}
