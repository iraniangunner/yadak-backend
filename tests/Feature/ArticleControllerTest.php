<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ArticleControllerTest extends TestCase
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
    // index / show عمومی
    // ------------------------------------------------------------------

    public function test_guest_can_list_only_published_articles(): void
    {
        Article::factory()->create(['is_published' => true, 'published_at' => now()->subDay()]);
        Article::factory()->create(['is_published' => false]);
        Article::factory()->create(['is_published' => true, 'published_at' => now()->addDay()]); // آینده

        $response = $this->getJson('/api/articles');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_guest_cannot_view_unpublished_article(): void
    {
        $article = Article::factory()->create(['is_published' => false]);

        $response = $this->getJson("/api/articles/{$article->slug}");

        $response->assertStatus(404);
    }

    public function test_guest_cannot_view_future_scheduled_article(): void
    {
        $article = Article::factory()->create([
            'is_published' => true,
            'published_at' => now()->addDay(),
        ]);

        $response = $this->getJson("/api/articles/{$article->slug}");

        $response->assertStatus(404);
    }

    public function test_guest_can_view_published_article_with_related_products(): void
    {
        $article = Article::factory()->create(['is_published' => true, 'published_at' => now()->subHour()]);
        $product = Product::factory()->create();
        $article->products()->attach($product->id, ['sort_order' => 0]);

        $response = $this->getJson("/api/articles/{$article->slug}");

        $response->assertStatus(200)
            ->assertJsonPath('article.id', $article->id)
            ->assertJsonCount(1, 'article.products');
    }

    // ------------------------------------------------------------------
    // دسترسی ادمین
    // ------------------------------------------------------------------

    public function test_guest_cannot_create_article(): void
    {
        $this->postJson('/api/admin/articles', [])->assertStatus(401);
    }

    public function test_non_admin_cannot_create_article(): void
    {
        /** @var \App\Models\User $sales */
        $sales = User::factory()->create(['role' => User::ROLE_SALES]);
        Passport::actingAs($sales);

        $this->postJson('/api/admin/articles', [])->assertStatus(403);
    }

    public function test_admin_index_shows_unpublished_articles_too(): void
    {
        $this->actingAsAdmin();

        Article::factory()->create(['is_published' => true]);
        Article::factory()->create(['is_published' => false]);

        $response = $this->getJson('/api/admin/articles');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_article_with_products_and_thumbnail(): void
    {
        Storage::fake('public');
        $admin = $this->actingAsAdmin();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $response = $this->post('/api/admin/articles', [
            'title' => 'راهنمای تعویض روغن',
            'content' => 'متن کامل مقاله...',
            'is_published' => '1',
            'thumbnail' => UploadedFile::fake()->image('article.jpg'),
            'product_ids' => [$productA->id, $productB->id],
        ]);

        $response->assertStatus(201);

        $article = Article::first();
        $this->assertEquals($admin->id, $article->author_id);
        $this->assertNotNull($article->thumbnail);
        $this->assertNotNull($article->published_at);
        $this->assertCount(2, $article->products);
    }

    public function test_store_generates_unique_slug_for_duplicate_titles(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/articles', [
            'title' => 'عنوان تکراری',
            'content' => 'متن اول',
        ])->assertStatus(201);

        $response = $this->postJson('/api/admin/articles', [
            'title' => 'عنوان تکراری',
            'content' => 'متن دوم',
        ]);

        $response->assertStatus(201);

        $slugs = Article::pluck('slug');
        $this->assertEquals(2, $slugs->unique()->count());
    }

    public function test_unpublished_article_has_no_published_at(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/articles', [
            'title' => 'پیش‌نویس',
            'content' => 'متن',
            'is_published' => false,
        ]);

        $response->assertStatus(201)->assertJsonPath('article.published_at', null);
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_admin_can_update_article_and_replace_product_list(): void
    {
        $this->actingAsAdmin();

        $article = Article::factory()->create();
        $oldProduct = Product::factory()->create();
        $newProduct = Product::factory()->create();
        $article->products()->attach($oldProduct->id, ['sort_order' => 0]);

        $response = $this->putJson("/api/admin/articles/{$article->id}", [
            'product_ids' => [$newProduct->id],
        ]);

        $response->assertStatus(200);

        $article->refresh();
        $this->assertCount(1, $article->products);
        $this->assertEquals($newProduct->id, $article->products->first()->id);
    }

    public function test_publishing_article_without_explicit_date_sets_published_at_now(): void
    {
        $this->actingAsAdmin();

        $article = Article::factory()->create(['is_published' => false, 'published_at' => null]);

        $response = $this->putJson("/api/admin/articles/{$article->id}", [
            'is_published' => true,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($article->fresh()->published_at);
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    public function test_admin_can_delete_article_and_its_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $path = UploadedFile::fake()->image('a.jpg')->store('articles', 'public');
        $article = Article::factory()->create(['thumbnail' => $path]);

        $this->deleteJson("/api/admin/articles/{$article->id}")->assertStatus(200);

        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
        $this->publicDisk()->assertMissing($path);
    }
}