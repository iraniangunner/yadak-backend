<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCustomer(): User
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        return $customer;
    }

    public function test_guest_cannot_access_addresses(): void
    {
        $this->getJson('/api/addresses')->assertStatus(401);
    }

    public function test_customer_can_create_address(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/addresses', [
            'title' => 'خانه',
            'receiver_name' => 'علی رضایی',
            'receiver_phone' => '09121234567',
            'city' => 'تهران',
            'full_address' => 'خیابان آزادی، پلاک ۱۰',
        ]);

        $response->assertStatus(201)->assertJsonPath('address.city', 'تهران');
    }

    public function test_store_fails_without_required_fields(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/addresses', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_name', 'receiver_phone', 'city', 'full_address']);
    }

    public function test_store_fails_with_invalid_phone_format(): void
    {
        $this->actingAsCustomer();

        $response = $this->postJson('/api/addresses', [
            'receiver_name' => 'علی',
            'receiver_phone' => '12345',
            'city' => 'تهران',
            'full_address' => 'آدرس',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('receiver_phone');
    }

    public function test_setting_default_unsets_other_addresses(): void
    {
        $customer = $this->actingAsCustomer();

        $first = Address::factory()->create(['user_id' => $customer->id, 'is_default' => true]);

        $response = $this->postJson('/api/addresses', [
            'receiver_name' => 'علی',
            'receiver_phone' => '09121234567',
            'city' => 'تهران',
            'full_address' => 'آدرس دوم',
            'is_default' => true,
        ]);

        $response->assertStatus(201);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($response->json('address.is_default'));
    }

    public function test_customer_can_list_own_addresses_with_default_first(): void
    {
        $customer = $this->actingAsCustomer();

        Address::factory()->create(['user_id' => $customer->id, 'is_default' => false]);
        $default = Address::factory()->create(['user_id' => $customer->id, 'is_default' => true]);

        Address::factory()->create(); // متعلق به کاربر دیگه

        $response = $this->getJson('/api/addresses');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals($default->id, $response->json('data.0.id'));
    }

    public function test_customer_can_update_own_address(): void
    {
        $customer = $this->actingAsCustomer();

        $address = Address::factory()->create(['user_id' => $customer->id, 'city' => 'تهران']);

        $response = $this->putJson("/api/addresses/{$address->id}", ['city' => 'مشهد']);

        $response->assertStatus(200)->assertJsonPath('address.city', 'مشهد');
    }

    public function test_cannot_update_others_address(): void
    {
        $this->actingAsCustomer();

        $othersAddress = Address::factory()->create();

        $response = $this->putJson("/api/addresses/{$othersAddress->id}", ['city' => 'مشهد']);

        $response->assertStatus(403);
    }

    public function test_customer_can_delete_own_address(): void
    {
        $customer = $this->actingAsCustomer();

        $address = Address::factory()->create(['user_id' => $customer->id]);

        $this->deleteJson("/api/addresses/{$address->id}")->assertStatus(200);

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_cannot_delete_others_address(): void
    {
        $this->actingAsCustomer();

        $othersAddress = Address::factory()->create();

        $response = $this->deleteJson("/api/addresses/{$othersAddress->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('addresses', ['id' => $othersAddress->id]);
    }
}
