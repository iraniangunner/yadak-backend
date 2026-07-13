<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BannerControllerTest extends TestCase
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

    public function test_guest_can_list_only_active_banners_ordered(): void
    {
        Banner::factory()->create(['is_active' => true, 'sort_order' => 2, 'title' => 'دوم']);
        Banner::factory()->create(['is_active' => true, 'sort_order' => 1, 'title' => 'اول']);
        Banner::factory()->create(['is_active' => false, 'title' => 'غیرفعال']);

        $response = $this->getJson('/api/banners');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');

        $this->assertEquals(['اول', 'دوم'], $titles->values()->all());
    }

    public function test_guest_cannot_create_banner(): void
    {
        $this->postJson('/api/admin/banners', [])->assertStatus(401);
    }

    public function test_non_admin_cannot_create_banner(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->postJson('/api/admin/banners', [])->assertStatus(403);
    }

    public function test_admin_can_create_banner_with_product_link(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $product = Product::factory()->create();

        $response = $this->post('/api/admin/banners', [
            'title' => 'حراج تابستانه',
            'image' => UploadedFile::fake()->image('banner.jpg'),
            'product_id' => $product->id,
            'sort_order' => 1,
        ]);

        $response->assertStatus(201)->assertJsonPath('banner.title', 'حراج تابستانه');

        $banner = Banner::first();
        $this->assertNotNull($banner->image);
        $this->publicDisk()->assertExists($banner->image);
    }

    public function test_store_fails_without_image(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/banners', [
            'title' => 'بدون عکس',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_banner_and_replace_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $oldPath = UploadedFile::fake()->image('old.jpg')->store('banners', 'public');
        $banner = Banner::factory()->create(['image' => $oldPath]);

        $response = $this->post("/api/admin/banners/{$banner->id}", [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('new.jpg'),
            'is_active' => false,
        ]);

        $response->assertStatus(200)->assertJsonPath('banner.is_active', false);

        $this->publicDisk()->assertMissing($oldPath);
    }

    public function test_admin_can_delete_banner_and_its_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $path = UploadedFile::fake()->image('b.jpg')->store('banners', 'public');
        $banner = Banner::factory()->create(['image' => $path]);

        $this->deleteJson("/api/admin/banners/{$banner->id}")->assertStatus(200);

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
        $this->publicDisk()->assertMissing($path);
    }
}
