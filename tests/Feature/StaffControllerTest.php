<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * تست‌های StaffController.
 *
 * نکته: چون همه‌ی route های /api/staff پشت ['auth:api', 'role:admin'] هستن،
 * برای هر تست باید مشخص کنیم کاربر لاگین‌شده چه نقشی داره. از Passport::actingAs
 * استفاده می‌کنیم که نیازی به توکن واقعی نداره و مستقیم کاربر رو authenticate می‌کنه.
 */
class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        Passport::actingAs($admin);

        return $admin;
    }

    // ------------------------------------------------------------------
    // دسترسی / احراز هویت
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_staff_routes(): void
    {
        $this->getJson('/api/staff')->assertStatus(401);
        $this->postJson('/api/staff', [])->assertStatus(401);
    }

    public function test_non_admin_staff_cannot_list_staff(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->getJson('/api/staff')->assertStatus(403);
    }

    public function test_customer_cannot_access_staff_routes(): void
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        $this->getJson('/api/staff')->assertStatus(403);
        $this->postJson('/api/staff', [])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_admin_can_list_only_staff_users(): void
    {
        $this->actingAsAdmin();

        User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        User::factory()->create(['role' => User::ROLE_SUPPORT]);
        User::factory()->create(['role' => User::ROLE_CUSTOMER]); // نباید توی نتیجه باشه

        $response = $this->getJson('/api/staff');

        $response->assertStatus(200);

        $roles = collect($response->json('data'))->pluck('role')->all();

        $this->assertNotContains(User::ROLE_CUSTOMER, $roles);
        $this->assertContains(User::ROLE_WAREHOUSE, $roles);
        $this->assertContains(User::ROLE_SUPPORT, $roles);
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_staff_user(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/staff', [
            'name' => 'کارمند انبار',
            'email' => 'warehouse@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_WAREHOUSE,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', User::ROLE_WAREHOUSE)
            ->assertJsonPath('user.email', 'warehouse@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'warehouse@example.com',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $created = User::where('email', 'warehouse@example.com')->first();
        $this->assertTrue(Hash::check('secret123', $created->password));
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/staff', [
            'name' => 'کاربر نامعتبر',
            'email' => 'invalid-role@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'customer', // customer نباید از این مسیر قابل ساخت باشه
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-role@example.com']);
    }

    public function test_store_fails_with_completely_unknown_role(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/staff', [
            'name' => 'کاربر نامعتبر',
            'email' => 'unknown-role@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'super-admin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_store_fails_with_duplicate_email(): void
    {
        $this->actingAsAdmin();

        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/staff', [
            'name' => 'کارمند جدید',
            'email' => 'duplicate@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_SALES,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_store_fails_without_password_confirmation(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/staff', [
            'name' => 'کارمند جدید',
            'email' => 'no-confirm@example.com',
            'password' => 'secret123',
            'role' => User::ROLE_SUPPORT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_non_admin_cannot_create_staff_user(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $response = $this->postJson('/api/staff', [
            'name' => 'تلاش غیرمجاز',
            'email' => 'unauthorized@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => User::ROLE_WAREHOUSE,
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.com']);
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_admin_can_update_staff_role_and_status(): void
    {
        $this->actingAsAdmin();

        /** @var \App\Models\User $staff */
        $staff = User::factory()->create([
            'role' => User::ROLE_SUPPORT,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/staff/{$staff->id}", [
            'role' => User::ROLE_SALES,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.role', User::ROLE_SALES)
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'role' => User::ROLE_SALES,
            'is_active' => false,
        ]);
    }

    public function test_update_fails_for_customer_user(): void
    {
        $this->actingAsAdmin();

        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->putJson("/api/staff/{$customer->id}", [
            'role' => User::ROLE_SALES,
        ]);

        $response->assertStatus(404);
    }

    public function test_update_fails_with_invalid_role(): void
    {
        $this->actingAsAdmin();

        /** @var \App\Models\User $staff */
        $staff = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $response = $this->putJson("/api/staff/{$staff->id}", [
            'role' => 'not-a-real-role',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_non_admin_cannot_update_staff(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);

        /** @var \App\Models\User $staffTarget */
        $staffTarget = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        Passport::actingAs($sales);

        $response = $this->putJson("/api/staff/{$staffTarget->id}", [
            'role' => User::ROLE_WAREHOUSE,
        ]);

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // destroy (غیرفعال‌سازی)
    // ------------------------------------------------------------------

    public function test_admin_can_deactivate_staff_user(): void
    {
        $this->actingAsAdmin();

        /** @var \App\Models\User $staff */
        $staff = User::factory()->create([
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/staff/{$staff->id}");

        $response->assertStatus(200);

        // کاربر واقعاً حذف نشده، فقط غیرفعال شده
        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'is_active' => false,
        ]);
    }

    public function test_destroy_fails_for_customer_user(): void
    {
        $this->actingAsAdmin();

        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->deleteJson("/api/staff/{$customer->id}");

        $response->assertStatus(404);
    }

    public function test_non_admin_cannot_deactivate_staff(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);

        /** @var \App\Models\User $staffTarget */
        $staffTarget = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        Passport::actingAs($sales);

        $response = $this->deleteJson("/api/staff/{$staffTarget->id}");

        $response->assertStatus(403);
    }
}
