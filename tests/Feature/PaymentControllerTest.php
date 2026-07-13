<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeZarinpalRequestSuccess(string $authority = 'A00000000000000000000000000123456'): void
    {
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'authority' => $authority,
                    'fee_type' => 'Merchant',
                    'fee' => 1000,
                ],
                'errors' => [],
            ], 200),
        ]);
    }

    private function fakeZarinpalRequestFailure(): void
    {
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [],
                'errors' => ['code' => -9, 'message' => 'merchant_id نامعتبر است.'],
            ], 200),
        ]);
    }

    private function fakeZarinpalVerifySuccess(string $refId = '123456789'): void
    {
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'ref_id' => $refId,
                    'card_pan' => '502229******5995',
                ],
                'errors' => [],
            ], 200),
        ]);
    }

    private function fakeZarinpalVerifyFailure(): void
    {
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => [],
                'errors' => ['code' => -22, 'message' => 'تراکنش ناموفق بود.'],
            ], 200),
        ]);
    }

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '09121234567',
        ]);
        Passport::actingAs($customer);

        return $customer;
    }

    // ------------------------------------------------------------------
    // initiate
    // ------------------------------------------------------------------

    public function test_guest_cannot_initiate_payment(): void
    {
        $order = Order::factory()->create(['status' => Order::STATUS_AWAITING_PAYMENT]);

        $this->postJson("/api/orders/{$order->id}/pay")->assertStatus(401);
    }

    public function test_customer_cannot_initiate_payment_for_others_order(): void
    {
        $this->actingAsCustomer();

        $order = Order::factory()->create(['status' => Order::STATUS_AWAITING_PAYMENT]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(403);
    }

    public function test_initiate_fails_if_order_not_awaiting_payment(): void
    {
        $customer = $this->actingAsCustomer();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_PENDING_REVIEW,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(422);
    }

    public function test_customer_can_initiate_payment_successfully(): void
    {
        $customer = $this->actingAsCustomer();
        $this->fakeZarinpalRequestSuccess('A000000000000000000000000000TEST1');

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'total_amount' => 250000,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(200)
            ->assertJsonStructure(['payment_url', 'expires_at']);

        $this->assertStringContainsString('A000000000000000000000000000TEST1', $response->json('payment_url'));

        $order->refresh();
        $this->assertEquals('A000000000000000000000000000TEST1', $order->payment_authority);
        $this->assertNotNull($order->payment_link_expires_at);
    }

    public function test_initiate_reuses_existing_unexpired_authority(): void
    {
        $customer = $this->actingAsCustomer();
        $this->fakeZarinpalRequestSuccess();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'EXISTING-AUTHORITY',
            'payment_link_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(200);
        $this->assertStringContainsString('EXISTING-AUTHORITY', $response->json('payment_url'));

        // نباید دوباره به زرین‌پال درخواست جدید زده باشه
        Http::assertNothingSent();
    }

    public function test_initiate_returns_502_when_zarinpal_fails(): void
    {
        $customer = $this->actingAsCustomer();
        $this->fakeZarinpalRequestFailure();

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => Order::STATUS_AWAITING_PAYMENT,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/pay");

        $response->assertStatus(502);
    }

    // ------------------------------------------------------------------
    // callback
    // ------------------------------------------------------------------

    public function test_callback_redirects_not_found_for_unknown_authority(): void
    {
        $response = $this->get('/api/payment/callback?Authority=UNKNOWN&Status=OK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=not_found', $response->headers->get('Location'));
    }

    public function test_callback_handles_already_paid_order_without_reverifying(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PAID,
            'payment_authority' => 'AUTH-PAID',
        ]);

        $response = $this->get('/api/payment/callback?Authority=AUTH-PAID&Status=OK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=already_paid', $response->headers->get('Location'));

        Http::assertNothingSent();
    }

    public function test_callback_handles_user_cancelled_payment(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-CANCEL',
            'payment_link_expires_at' => now()->addMinutes(20),
        ]);

        $response = $this->get('/api/payment/callback?Authority=AUTH-CANCEL&Status=NOK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=failed', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->status); // وضعیت عوض نشده، فقط authority پاک شده
        $this->assertNull($order->payment_authority);
    }

    public function test_callback_handles_expired_payment_link(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-EXPIRED',
            'payment_link_expires_at' => now()->subMinute(),
        ]);

        $response = $this->get('/api/payment/callback?Authority=AUTH-EXPIRED&Status=OK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=expired', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(Order::STATUS_EXPIRED, $order->status);
    }

    public function test_callback_marks_order_paid_on_successful_verification(): void
    {
        $this->fakeZarinpalVerifySuccess('REF-999');

        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-OK',
            'payment_link_expires_at' => now()->addMinutes(20),
            'total_amount' => 250000,
        ]);

        $response = $this->get('/api/payment/callback?Authority=AUTH-OK&Status=OK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(Order::STATUS_PAID, $order->status);
        $this->assertEquals('REF-999', $order->payment_ref_id);
        $this->assertNotNull($order->paid_at);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => Order::STATUS_PAID,
        ]);
    }

    public function test_callback_keeps_order_awaiting_payment_when_verify_fails(): void
    {
        $this->fakeZarinpalVerifyFailure();

        $order = Order::factory()->create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-BADVERIFY',
            'payment_link_expires_at' => now()->addMinutes(20),
        ]);

        $response = $this->get('/api/payment/callback?Authority=AUTH-BADVERIFY&Status=OK');

        $response->assertStatus(302);
        $this->assertStringContainsString('status=failed', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertNull($order->payment_ref_id);
    }
}
