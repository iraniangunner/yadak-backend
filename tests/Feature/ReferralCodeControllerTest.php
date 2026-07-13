<?php

namespace Tests\Feature;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ReferralCodeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_access_referral_codes(): void
    {
        $this->getJson('/api/admin/referral-codes')->assertStatus(401);
    }

    public function test_warehouse_cannot_manage_referral_codes(): void
    {
        // برخلاف محصول/سفارش، مدیریت کد معرف فقط برای admin هست، نه warehouse
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->getJson('/api/admin/referral-codes')->assertStatus(403);
    }

    public function test_admin_can_create_referral_code(): void
    {
        $this->actingAsAdmin();

        $seller = User::factory()->create(['role' => User::ROLE_SALES]);

        $response = $this->postJson('/api/admin/referral-codes', [
            'code' => 'ali10',
            'user_id' => $seller->id,
            'commission_type' => 'percentage',
            'commission_value' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('referral_code.code', 'ALI10')
            ->assertJsonPath('referral_code.user_id', $seller->id);
    }

    public function test_store_fails_with_duplicate_code(): void
    {
        $this->actingAsAdmin();

        ReferralCode::factory()->create(['code' => 'ALI10']);
        $seller = User::factory()->create();

        $response = $this->postJson('/api/admin/referral-codes', [
            'code' => 'ali10',
            'user_id' => $seller->id,
            'commission_type' => 'percentage',
            'commission_value' => 5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_store_fails_with_invalid_user_id(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/referral-codes', [
            'code' => 'BADUSER',
            'user_id' => 999999,
            'commission_type' => 'percentage',
            'commission_value' => 10,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_store_fails_with_commission_percentage_over_100(): void
    {
        $this->actingAsAdmin();

        $seller = User::factory()->create();

        $response = $this->postJson('/api/admin/referral-codes', [
            'code' => 'OVER100',
            'user_id' => $seller->id,
            'commission_type' => 'percentage',
            'commission_value' => 150,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('commission_value');
    }

    public function test_admin_can_update_referral_code(): void
    {
        $this->actingAsAdmin();

        $referralCode = ReferralCode::factory()->create(['commission_value' => 10]);

        $response = $this->putJson("/api/admin/referral-codes/{$referralCode->id}", [
            'commission_value' => 15,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('referral_code.commission_value', 15)
            ->assertJsonPath('referral_code.is_active', false);
    }

    public function test_admin_can_delete_referral_code(): void
    {
        $this->actingAsAdmin();

        $referralCode = ReferralCode::factory()->create();

        $this->deleteJson("/api/admin/referral-codes/{$referralCode->id}")->assertStatus(200);

        $this->assertDatabaseMissing('referral_codes', ['id' => $referralCode->id]);
    }

    public function test_admin_can_filter_referral_codes_by_user(): void
    {
        $this->actingAsAdmin();

        $sellerA = User::factory()->create();
        $sellerB = User::factory()->create();

        ReferralCode::factory()->create(['user_id' => $sellerA->id]);
        ReferralCode::factory()->create(['user_id' => $sellerB->id]);

        $response = $this->getJson("/api/admin/referral-codes?user_id={$sellerA->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
