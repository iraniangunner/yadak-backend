<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BrandControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return \Illuminate\Filesystem\FilesystemAdapter
     */
    private function publicDisk()
    {
        return Storage::disk('public');
    }

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    // ------------------------------------------------------------------
    // index (عمومی)
    // ------------------------------------------------------------------

    public function test_guest_can_list_active_brands(): void
    {
        Brand::factory()->create(['is_active' => true]);
        Brand::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/brands');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_brand_with_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/brands', [
            'name' => 'بوش',
            'thumbnail' => UploadedFile::fake()->image('bosch.jpg'),
        ]);

        $response->assertStatus(201)->assertJsonPath('brand.name', 'بوش');

        $brand = Brand::first();
        $this->assertNotNull($brand->thumbnail);
        $this->publicDisk()->assertExists($brand->thumbnail);
    }

    public function test_warehouse_can_also_create_brand(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $response = $this->postJson('/api/admin/brands', ['name' => 'دنسو']);

        $response->assertStatus(201);
    }

    public function test_sales_cannot_create_brand(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $response = $this->postJson('/api/admin/brands', ['name' => 'بوش']);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_create_brand(): void
    {
        $response = $this->postJson('/api/admin/brands', ['name' => 'بوش']);

        $response->assertStatus(401);
    }

    public function test_store_fails_without_name(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/brands', []);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_admin_can_replace_brand_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $oldPath = UploadedFile::fake()->image('old.jpg')->store('brands', 'public');
        $brand = Brand::factory()->create(['thumbnail' => $oldPath]);

        $response = $this->putJson("/api/admin/brands/{$brand->id}", [
            'thumbnail' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertStatus(200);

        $this->publicDisk()->assertMissing($oldPath);
        $this->publicDisk()->assertExists($response->json('brand.thumbnail'));
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    public function test_admin_can_delete_brand_and_its_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $path = UploadedFile::fake()->image('bosch.jpg')->store('brands', 'public');
        $brand = Brand::factory()->create(['thumbnail' => $path]);

        $response = $this->deleteJson("/api/admin/brands/{$brand->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->publicDisk()->assertMissing($path);
    }
}