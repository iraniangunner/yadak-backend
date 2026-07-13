<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CategoryBrandSalesStopTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_stop_sales_for_category(): void
    {
        $this->actingAsAdmin();

        $category = Category::factory()->create(['sales_stopped' => false]);

        $response = $this->putJson("/api/admin/categories/{$category->id}", [
            'sales_stopped' => true,
        ]);

        $response->assertStatus(200)->assertJsonPath('category.sales_stopped', true);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'sales_stopped' => true,
        ]);
    }

    public function test_admin_can_resume_sales_for_category(): void
    {
        $this->actingAsAdmin();

        $category = Category::factory()->create(['sales_stopped' => true]);

        $response = $this->putJson("/api/admin/categories/{$category->id}", [
            'sales_stopped' => false,
        ]);

        $response->assertStatus(200)->assertJsonPath('category.sales_stopped', false);
    }

    public function test_admin_can_stop_sales_for_brand(): void
    {
        $this->actingAsAdmin();

        $brand = Brand::factory()->create(['sales_stopped' => false]);

        $response = $this->putJson("/api/admin/brands/{$brand->id}", [
            'sales_stopped' => true,
        ]);

        $response->assertStatus(200)->assertJsonPath('brand.sales_stopped', true);
    }

    public function test_category_with_stopped_sales_still_visible_in_public_listing(): void
    {
        // sales_stopped فقط جلوی خرید رو می‌گیره، نه مشاهده - پس دسته باید
        // همچنان توی لیست عمومی باشه (برخلاف is_active=false که مخفی می‌کنه).
        $category = Category::factory()->create(['is_active' => true, 'sales_stopped' => true]);

        $response = $this->getJson('/api/categories');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($category->id));
    }

    public function test_warehouse_can_also_stop_sales(): void
    {
        /** @var \App\Models\User $warehouse */
        $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);
        Passport::actingAs($warehouse);

        $brand = Brand::factory()->create(['sales_stopped' => false]);

        $response = $this->putJson("/api/admin/brands/{$brand->id}", [
            'sales_stopped' => true,
        ]);

        $response->assertStatus(200);
    }
}