<?php

namespace Tests\Feature;

use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MarketingCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    private function customer(?string $city = null): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '0912' . rand(1000000, 9999999),
            'city' => $city,
        ]);
    }

    // ------------------------------------------------------------------
    // دسترسی
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_marketing_endpoints(): void
    {
        $this->postJson('/api/admin/marketing/preview', [])->assertStatus(401);
        $this->postJson('/api/admin/marketing/send', [])->assertStatus(401);
        $this->getJson('/api/admin/marketing/campaigns')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_marketing_endpoints(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $this->postJson('/api/admin/marketing/preview', [])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // preview
    // ------------------------------------------------------------------

    public function test_preview_returns_correct_count_without_sending(): void
    {
        Http::fake();
        $this->actingAsAdmin();

        $this->customer('تهران');
        $this->customer('تهران');
        $this->customer('مشهد');

        $response = $this->postJson('/api/admin/marketing/preview', ['city' => 'تهران']);

        $response->assertStatus(200)->assertJsonPath('recipient_count', 2);

        Http::assertNothingSent();
        $this->assertDatabaseCount('marketing_campaigns', 0);
    }

    public function test_preview_fails_with_invalid_filter(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/marketing/preview', ['vehicle_id' => 999999]);

        $response->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    // ------------------------------------------------------------------
    // send
    // ------------------------------------------------------------------

    public function test_send_fails_without_message(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/marketing/send', []);

        $response->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_admin_can_send_campaign_to_filtered_customers(): void
    {
        Http::fake();
        $admin = $this->actingAsAdmin();

        $this->customer('تهران');
        $this->customer('تهران');
        $this->customer('مشهد'); // نباید پیامک بگیره

        $response = $this->postJson('/api/admin/marketing/send', [
            'city' => 'تهران',
            'message' => 'تخفیف ویژه برای مشتریان تهرانی!',
        ]);

        $response->assertStatus(201)->assertJsonPath('campaign.recipient_count', 2);

        $this->assertDatabaseHas('marketing_campaigns', [
            'sent_by' => $admin->id,
            'recipient_count' => 2,
        ]);

        Http::assertSentCount(2);
    }

    public function test_send_with_no_matching_customers_creates_zero_recipient_campaign(): void
    {
        Http::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/marketing/send', [
            'city' => 'شهر-ناموجود',
            'message' => 'پیام تست',
        ]);

        $response->assertStatus(201)->assertJsonPath('campaign.recipient_count', 0);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_admin_can_list_campaign_history(): void
    {
        $admin = $this->actingAsAdmin();

        MarketingCampaign::create([
            'sent_by' => $admin->id,
            'filters' => ['city' => 'تهران'],
            'message' => 'پیام قدیمی',
            'recipient_count' => 5,
        ]);

        $response = $this->getJson('/api/admin/marketing/campaigns');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
