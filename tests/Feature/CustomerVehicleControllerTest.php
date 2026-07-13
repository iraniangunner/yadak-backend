<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CustomerVehicleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_my_vehicles(): void
    {
        $this->getJson('/api/my-vehicles')->assertStatus(401);
        $this->postJson('/api/my-vehicles', [])->assertStatus(401);
    }

    public function test_customer_can_add_and_list_own_vehicle(): void
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        $vehicle = Vehicle::factory()->create();

        $this->postJson('/api/my-vehicles', ['vehicle_id' => $vehicle->id])
            ->assertStatus(201);

        $response = $this->getJson('/api/my-vehicles');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('vehicles'));
    }

    public function test_adding_same_vehicle_twice_does_not_duplicate(): void
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        $vehicle = Vehicle::factory()->create();

        $this->postJson('/api/my-vehicles', ['vehicle_id' => $vehicle->id])->assertStatus(201);
        $this->postJson('/api/my-vehicles', ['vehicle_id' => $vehicle->id])->assertStatus(201);

        $this->assertDatabaseCount('customer_vehicle', 1);
    }

    public function test_store_fails_for_nonexistent_vehicle(): void
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        $response = $this->postJson('/api/my-vehicles', ['vehicle_id' => 99999]);

        $response->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    public function test_customer_can_remove_own_vehicle(): void
    {
        /** @var \App\Models\User $customer */
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Passport::actingAs($customer);

        $vehicle = Vehicle::factory()->create();
        $customer->vehicles()->attach($vehicle->id);

        $response = $this->deleteJson("/api/my-vehicles/{$vehicle->id}");

        $response->assertStatus(200);
        $this->assertDatabaseCount('customer_vehicle', 0);
    }

    public function test_removing_vehicle_does_not_affect_other_customers(): void
    {
        /** @var \App\Models\User $customerA */
        $customerA = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        /** @var \App\Models\User $customerB */
        $customerB = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $vehicle = Vehicle::factory()->create();
        $customerA->vehicles()->attach($vehicle->id);
        $customerB->vehicles()->attach($vehicle->id);

        Passport::actingAs($customerA);
        $this->deleteJson("/api/my-vehicles/{$vehicle->id}")->assertStatus(200);

        $this->assertDatabaseHas('customer_vehicle', [
            'user_id' => $customerB->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }
}
