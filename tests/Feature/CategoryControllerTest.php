<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        /** @var \App\Models\User $admin */
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Passport::actingAs($admin);

        return $admin;
    }

    public function test_guest_can_list_categories_flat(): void
    {
        Category::factory()->create(['is_active' => true]);
        Category::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/categories');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_guest_can_list_categories_as_tree(): void
    {
        $root = Category::factory()->create(['is_active' => true]);
        Category::factory()->create(['parent_id' => $root->id, 'is_active' => true]);

        $response = $this->getJson('/api/categories?tree=1');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['children']);
    }

    public function test_admin_can_create_category_with_parent(): void
    {
        $this->actingAsAdmin();

        $parent = Category::factory()->create();

        $response = $this->postJson('/api/admin/categories', [
            'name' => 'لوازم موتوری',
            'parent_id' => $parent->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('category.parent_id', $parent->id);
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $this->actingAsAdmin();

        $category = Category::factory()->create();

        $response = $this->putJson("/api/admin/categories/{$category->id}", [
            'parent_id' => $category->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_deleting_category_nullifies_children_parent_id(): void
    {
        $this->actingAsAdmin();

        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $this->deleteJson("/api/admin/categories/{$parent->id}")->assertStatus(200);

        $this->assertDatabaseHas('categories', [
            'id' => $child->id,
            'parent_id' => null,
        ]);
    }

    public function test_non_admin_cannot_manage_categories(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->postJson('/api/admin/categories', ['name' => 'تست'])->assertStatus(403);
    }
}
