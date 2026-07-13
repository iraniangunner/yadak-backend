<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class VehicleControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    public function test_guest_can_search_vehicles(): void
    {
        Vehicle::factory()->create(['brand' => 'پژو', 'model' => '206']);
        Vehicle::factory()->create(['brand' => 'سایپا', 'model' => 'تیبا']);

        $response = $this->getJson('/api/vehicles?search=پژو');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_create_vehicle(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/vehicles', [
            'brand' => 'پژو',
            'model' => '206',
            'year_from' => 1385,
            'year_to' => 1395,
        ]);

        $response->assertStatus(201)->assertJsonPath('vehicle.model', '206');
    }

    public function test_year_to_must_be_greater_or_equal_year_from(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/vehicles', [
            'brand' => 'پژو',
            'model' => '206',
            'year_from' => 1395,
            'year_to' => 1385,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('year_to');
    }

    public function test_admin_can_update_and_delete_vehicle(): void
    {
        $this->actingAsAdmin();

        $vehicle = Vehicle::factory()->create();

        $this->putJson("/api/admin/vehicles/{$vehicle->id}", ['model' => 'جدید'])
            ->assertStatus(200)
            ->assertJsonPath('vehicle.model', 'جدید');

        $this->deleteJson("/api/admin/vehicles/{$vehicle->id}")->assertStatus(200);

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }

    public function test_non_admin_cannot_manage_vehicles(): void
    {
        /** @var \App\Models\User $support */
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        Passport::actingAs($support);

        $this->postJson('/api/admin/vehicles', ['brand' => 'پژو', 'model' => '206'])
            ->assertStatus(403);
    }
}
