<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * تست‌های کامل لایه‌ی Auth.
 *
 * نکته‌ی مهم قبل از اجرا:
 * ۱. حتماً `php artisan passport:keys` رو یک بار روی محیط تست هم اجرا کرده باشید
 *    (یا در CI به صورت خودکار قبل از تست‌ها اجرا بشه)، چون بدون کلید خصوصی/عمومی
 *    برخی مسیرهای داخلی Passport ارور می‌دن، حتی وقتی خودِ درخواست oauth/token رو
 *    فیک می‌کنیم.
 * ۲. برای route هایی که خودشون HTTP request به /oauth/token می‌زنن (login, refresh,
 *    verify-otp) از Http::fake استفاده کردیم؛ یعنی این تست‌ها واقعاً از فرآیند صدور
 *    توکن Passport رد نمی‌شن، فقط رفتار AuthController رو تست می‌کنن. این عمداً هست:
 *    تست فرآیند واقعی OAuth باید integration test جدا با passport:client واقعی باشه.
 * ۳. برای route های پشت auth:api (logout, me) از Passport::actingAs استفاده شده که
 *    روش رسمی و توصیه‌شده‌ی خود Laravel Passport برای تست کردن این نوع مسیرهاست.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * یه پاسخ فیک استاندارد برای /oauth/token می‌سازه.
     */
    private function fakeOauthTokenResponse(): array
    {
        return [
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => $this->fakeJwt(),
            'refresh_token' => bin2hex(random_bytes(20)),
        ];
    }

    /**
     * یه JWT ساختگی با payload شامل sub (user id) و jti (token id) می‌سازه؛
     * برای تست منطق decode داخل AuthController::refresh لازمه.
     * توجه: امضا واقعی نیست چون کنترلر ما فقط payload رو decode می‌کنه، verify نمی‌کنه.
     */
    private function fakeJwt(?int $userId = null, ?string $jti = null): string
    {
        $header = $this->b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $this->b64([
            'sub' => $userId ?? 1,
            'jti' => $jti ?? bin2hex(random_bytes(8)),
            'exp' => now()->addHour()->timestamp,
        ]);

        return "{$header}.{$payload}.fakesignature";
    }

    private function b64(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------
    // register
    // ------------------------------------------------------------------

    public function test_register_creates_customer_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'علی رضایی',
            'email' => 'ali@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'ali@example.com')
            ->assertJsonPath('user.role', User::ROLE_CUSTOMER);

        $this->assertDatabaseHas('users', [
            'email' => 'ali@example.com',
            'role' => User::ROLE_CUSTOMER,
        ]);

        $user = User::where('email', 'ali@example.com')->first();
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_register_fails_without_password_confirmation(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'علی رضایی',
            'email' => 'ali@example.com',
            'password' => 'secret123',
            // password_confirmation عمداً حذف شده
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ali@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'علی رضایی',
            'email' => 'ali@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ------------------------------------------------------------------
    // login
    // ------------------------------------------------------------------

    public function test_login_success_returns_token(): void
    {
        Http::fake(['*/oauth/token' => Http::response($this->fakeOauthTokenResponse(), 200)]);

        /** @var \App\Models\User $user */
        $user = User::factory()->create([
            'email' => 'ali@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ali@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'refresh_token', 'user'])
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ali@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ali@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'email' => 'ali@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ali@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // refresh
    // ------------------------------------------------------------------

    public function test_refresh_returns_new_token_for_active_user(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create(['is_active' => true]);

        $fakeResponse = $this->fakeOauthTokenResponse();
        $fakeResponse['access_token'] = $this->fakeJwt($user->id);

        Http::fake(['*/oauth/token' => Http::response($fakeResponse, 200)]);

        $response = $this->postJson('/api/refresh', [
            'refresh_token' => 'some-valid-refresh-token',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_refresh_rejects_inactive_user(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create(['is_active' => false]);

        $fakeResponse = $this->fakeOauthTokenResponse();
        $fakeResponse['access_token'] = $this->fakeJwt($user->id);

        Http::fake(['*/oauth/token' => Http::response($fakeResponse, 200)]);

        $response = $this->postJson('/api/refresh', [
            'refresh_token' => 'some-valid-refresh-token',
        ]);

        $response->assertStatus(403);
    }

    public function test_refresh_fails_with_invalid_token(): void
    {
        Http::fake(['*/oauth/token' => Http::response(['error' => 'invalid_grant'], 401)]);

        $response = $this->postJson('/api/refresh', [
            'refresh_token' => 'invalid-token',
        ]);

        $response->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // send-otp
    // ------------------------------------------------------------------

    public function test_send_otp_creates_record_and_sends_sms(): void
    {
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('sendByTemplate')->once()->andReturn(true);
        });

        $response = $this->postJson('/api/send-otp', ['mobile' => '09121234567']);

        $response->assertStatus(200);
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_send_otp_blocks_repeated_request_within_two_minutes(): void
    {
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('sendByTemplate')->once()->andReturn(true);
        });

        $this->postJson('/api/send-otp', ['mobile' => '09121234567'])->assertStatus(200);

        // درخواست دوم بلافاصله بعد از اولی باید رد بشه
        $this->postJson('/api/send-otp', ['mobile' => '09121234567'])->assertStatus(429);

        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_send_otp_validates_mobile_format(): void
    {
        $response = $this->postJson('/api/send-otp', ['mobile' => '123']);

        $response->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    // ------------------------------------------------------------------
    // verify-otp
    // ------------------------------------------------------------------

    public function test_verify_otp_success_creates_new_user(): void
    {
        Http::fake(['*/oauth/token' => Http::response($this->fakeOauthTokenResponse(), 200)]);

        OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('123456'),
            'used' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_new_user', true)
            ->assertJsonPath('user.phone', '09121234567');

        $this->assertDatabaseHas('users', [
            'phone' => '09121234567',
            'phone_verified' => true,
            'role' => User::ROLE_CUSTOMER,
        ]);
    }

    public function test_verify_otp_success_for_existing_user(): void
    {
        Http::fake(['*/oauth/token' => Http::response($this->fakeOauthTokenResponse(), 200)]);

        /** @var \App\Models\User $user */
        $user = User::factory()->create([
            'phone' => '09121234567',
            'phone_verified' => false,
            'is_active' => true,
        ]);

        OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('654321'),
            'used' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '654321',
        ]);

        $response->assertStatus(200)->assertJsonPath('is_new_user', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone_verified' => true,
        ]);
    }

    public function test_verify_otp_fails_with_wrong_code_and_increments_attempts(): void
    {
        $otp = OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('123456'),
            'used' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '000000',
        ]);

        $response->assertStatus(401);

        $this->assertEquals(1, $otp->fresh()->attempts);
    }

    public function test_verify_otp_blocks_after_max_attempts(): void
    {
        OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('123456'),
            'used' => false,
            'attempts' => 5, // به سقف رسیده
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '123456', // حتی کد درست هم دیگه نباید قبول بشه
        ]);

        $response->assertStatus(429);
    }

    public function test_verify_otp_fails_with_expired_code(): void
    {
        OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('123456'),
            'used' => false,
            'attempts' => 0,
            'expires_at' => now()->subMinute(), // منقضی شده
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '123456',
        ]);

        $response->assertStatus(401);
    }

    public function test_verify_otp_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'phone' => '09121234567',
            'is_active' => false,
        ]);

        OtpCode::create([
            'mobile' => '09121234567',
            'code' => Hash::make('123456'),
            'used' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'mobile' => '09121234567',
            'code' => '123456',
        ]);

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // forgot-password / reset-password
    // ------------------------------------------------------------------

    public function test_forgot_password_returns_generic_message_for_existing_email(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'ali@example.com']);

        $response = $this->postJson('/api/forgot-password', ['email' => 'ali@example.com']);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی رمز عبور برایش ارسال شد.');
    }

    public function test_forgot_password_returns_same_generic_message_for_nonexistent_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/forgot-password', ['email' => 'not-exist@example.com']);

        // نکته‌ی امنیتی مهم: پیام باید دقیقاً همون چیزی باشه که برای ایمیل موجود برمی‌گرده
        $response->assertStatus(200)
            ->assertJsonPath('message', 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی رمز عبور برایش ارسال شد.');
    }

    // ------------------------------------------------------------------
    // logout / me  (پشت auth:api → از Passport::actingAs استفاده می‌کنیم)
    // ------------------------------------------------------------------

    public function test_me_returns_authenticated_user(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200)->assertJsonPath('id', $user->id);
    }

    public function test_me_fails_without_authentication(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        Passport::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200);
    }
}