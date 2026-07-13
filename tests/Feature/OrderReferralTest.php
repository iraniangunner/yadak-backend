<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralCode;
use App\Models\ReferralCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class OrderReferralTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'phone' => '09121234567']);
        Passport::actingAs($customer);

        return $customer;
    }

    private function fakeZarinpalVerifySuccess(string $refId = 'REF-1'): void
    {
        Http::fake([
            'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => $refId],
                'errors' => [],
            ], 200),
        ]);
    }

    // ------------------------------------------------------------------
    // ثبت سفارش با کد معرف
    // ------------------------------------------------------------------

    public function test_order_with_referral_code_creates_pending_commission(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        $referralCode = ReferralCode::factory()->create(['code' => 'SELLER1', 'commission_type' => ReferralCode::TYPE_PERCENTAGE, 'commission_value' => 10]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'seller1',
        ]);

        $response->assertStatus(201);

        $order = Order::first();
        $this->assertEquals($referralCode->id, $order->referral_code_id);

        $this->assertDatabaseHas('referral_commissions', [
            'order_id' => $order->id,
            'referral_code_id' => $referralCode->id,
            'user_id' => $referralCode->user_id,
            'commission_amount' => null,
            'status' => ReferralCommission::STATUS_PENDING,
        ]);
    }

    public function test_order_fails_with_invalid_referral_code(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'NOTREAL',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_fails_with_inactive_referral_code(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        ReferralCode::factory()->create(['code' => 'OFFCODE', 'is_active' => false]);

        $response = $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'OFFCODE',
        ]);

        $response->assertStatus(422);
    }

    public function test_order_without_referral_code_has_no_commission(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertDatabaseCount('referral_commissions', 0);
    }

    // ------------------------------------------------------------------
    // قطعی شدن پورسانت بعد از پرداخت موفق
    // ------------------------------------------------------------------

    public function test_commission_becomes_approved_with_amount_after_successful_payment(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 200000]);
        ReferralCode::factory()->create([
            'code' => 'SELLER2',
            'commission_type' => ReferralCode::TYPE_PERCENTAGE,
            'commission_value' => 10,
        ]);

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'SELLER2',
        ]);

        $order = Order::first();

        // شبیه‌سازی مسیر تایید ادمین + پرداخت موفق
        $order->update([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-REF',
            'payment_link_expires_at' => now()->addMinutes(20),
        ]);

        $this->fakeZarinpalVerifySuccess('REF-999');

        $this->get('/api/payment/callback?Authority=AUTH-REF&Status=OK')->assertStatus(302);

        $commission = ReferralCommission::where('order_id', $order->id)->first();

        $this->assertEquals(ReferralCommission::STATUS_APPROVED, $commission->status);
        $this->assertEquals(20000, $commission->commission_amount); // ۱۰٪ از ۲۰۰۰۰۰
    }

    // ------------------------------------------------------------------
    // لغو پورسانت
    // ------------------------------------------------------------------

    public function test_commission_cancelled_when_customer_cancels_order(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        ReferralCode::factory()->create(['code' => 'SELLER3']);

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'SELLER3',
        ]);

        $order = Order::first();

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(200);

        $commission = ReferralCommission::where('order_id', $order->id)->first();
        $this->assertEquals(ReferralCommission::STATUS_CANCELLED, $commission->status);
    }

    public function test_commission_cancelled_when_admin_cancels_order(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        ReferralCode::factory()->create(['code' => 'SELLER4']);

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'SELLER4',
        ]);

        $order = Order::first();

        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        $this->postJson("/api/admin/orders/{$order->id}/cancel")->assertStatus(200);

        $commission = ReferralCommission::where('order_id', $order->id)->first();
        $this->assertEquals(ReferralCommission::STATUS_CANCELLED, $commission->status);
    }

    public function test_commission_cancelled_when_payment_link_expires(): void
    {
        $this->actingAsCustomer();

        $product = Product::factory()->create(['price' => 100000]);
        ReferralCode::factory()->create(['code' => 'SELLER5']);

        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'referral_code' => 'SELLER5',
        ]);

        $order = Order::first();
        $order->update([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_authority' => 'AUTH-EXPIRED-REF',
            'payment_link_expires_at' => now()->subMinute(),
        ]);

        $this->get('/api/payment/callback?Authority=AUTH-EXPIRED-REF&Status=OK')->assertStatus(302);

        $commission = ReferralCommission::where('order_id', $order->id)->first();
        $this->assertEquals(ReferralCommission::STATUS_CANCELLED, $commission->status);
    }
}
