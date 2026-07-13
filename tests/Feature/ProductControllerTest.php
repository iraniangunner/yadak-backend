<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProductControllerTest extends TestCase
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
    // index / show (عمومی)
    // ------------------------------------------------------------------

    public function test_guest_can_list_only_active_products(): void
    {
        Product::factory()->create(['is_active' => true]);
        Product::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/products');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_filter_by_vehicle_id_returns_only_tagged_products(): void
    {
        $vehicle = Vehicle::factory()->create();
        $taggedProduct = Product::factory()->create();
        $otherProduct = Product::factory()->create();

        $taggedProduct->vehicles()->attach($vehicle->id);

        $response = $this->getJson("/api/products?vehicle_id={$vehicle->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($taggedProduct->id, $ids);
        $this->assertNotContains($otherProduct->id, $ids);
    }

    public function test_filter_by_brand_and_category(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();

        $matching = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id]);
        Product::factory()->create(); // بدون brand/category

        $response = $this->getJson("/api/products?brand_id={$brand->id}&category_id={$category->id}");

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$matching->id], $ids);
    }

    public function test_show_returns_product_with_relations(): void
    {
        $vehicle = Vehicle::factory()->create();
        $product = Product::factory()->create();
        $product->vehicles()->attach($vehicle->id);
        $product->images()->create(['path' => 'products/gallery/x.jpg', 'sort_order' => 0]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonCount(1, 'product.images')
            ->assertJsonCount(1, 'product.vehicles');
    }

    // ------------------------------------------------------------------
    // store
    // ------------------------------------------------------------------

    public function test_admin_can_create_product_with_thumbnail_gallery_and_vehicles(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $vehicle1 = Vehicle::factory()->create();
        $vehicle2 = Vehicle::factory()->create();

        // برای آپلود فایل باید از post() معمولی استفاده کنیم، نه postJson()،
        // چون postJson بدنه رو JSON می‌کنه و فایل رو نمی‌تونه بفرسته.
        $response = $this->post('/api/admin/products', [
            'title' => 'لنت ترمز جلو',
            'sku' => 'SKU-001',
            'price' => 250000,
            'thumbnail' => UploadedFile::fake()->image('thumb.jpg'),
            'images' => [
                UploadedFile::fake()->image('g1.jpg'),
                UploadedFile::fake()->image('g2.jpg'),
            ],
            'vehicle_ids' => [$vehicle1->id, $vehicle2->id],
        ]);

        $response->assertStatus(201);

        $product = Product::where('sku', 'SKU-001')->first();

        $this->assertNotNull($product);
        $this->assertNotNull($product->thumbnail);
        $this->publicDisk()->assertExists($product->thumbnail);

        $this->assertCount(2, $product->images);
        $this->assertCount(2, $product->vehicles);
    }

    public function test_store_fails_with_duplicate_sku(): void
    {
        $this->actingAsAdmin();

        Product::factory()->create(['sku' => 'DUP-001']);

        $response = $this->postJson('/api/admin/products', [
            'title' => 'محصول تکراری',
            'sku' => 'DUP-001',
            'price' => 10000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_compare_price_must_be_greater_or_equal_price(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/products', [
            'title' => 'محصول',
            'sku' => 'SKU-CMP',
            'price' => 100000,
            'compare_price' => 50000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('compare_price');
    }

    public function test_non_admin_cannot_create_product(): void
    {
        /** @var \App\Models\User $support */
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        Passport::actingAs($support);

        $response = $this->postJson('/api/admin/products', [
            'title' => 'محصول',
            'sku' => 'SKU-X',
            'price' => 10000,
        ]);

        $response->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // update
    // ------------------------------------------------------------------

    public function test_admin_can_update_product_and_replace_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $oldPath = UploadedFile::fake()->image('old.jpg')->store('products', 'public');
        $product = Product::factory()->create(['thumbnail' => $oldPath]);

        // برای آپلود فایل توی update (که route ش PUT هست)، از method spoofing
        // با _method=PUT روی یه درخواست POST استفاده می‌کنیم.
        $response = $this->post("/api/admin/products/{$product->id}", [
            '_method' => 'PUT',
            'thumbnail' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertStatus(200);

        $this->publicDisk()->assertMissing($oldPath);
        $this->publicDisk()->assertExists($response->json('product.thumbnail'));
    }

    public function test_updating_vehicle_ids_replaces_previous_set(): void
    {
        $this->actingAsAdmin();

        $oldVehicle = Vehicle::factory()->create();
        $newVehicle = Vehicle::factory()->create();

        $product = Product::factory()->create();
        $product->vehicles()->attach($oldVehicle->id);

        $response = $this->putJson("/api/admin/products/{$product->id}", [
            'vehicle_ids' => [$newVehicle->id],
        ]);

        $response->assertStatus(200);

        $product->refresh();
        $vehicleIds = $product->vehicles->pluck('id')->all();

        $this->assertEquals([$newVehicle->id], $vehicleIds);
    }

    public function test_update_ignores_own_sku_for_uniqueness_check(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create(['sku' => 'SAME-SKU']);

        // فرستادن همون sku که خودش داره نباید خطای unique بده
        $response = $this->putJson("/api/admin/products/{$product->id}", [
            'sku' => 'SAME-SKU',
            'title' => 'عنوان جدید',
        ]);

        $response->assertStatus(200)->assertJsonPath('product.title', 'عنوان جدید');
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    public function test_admin_can_delete_product_and_cleans_up_files(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $thumbPath = UploadedFile::fake()->image('thumb.jpg')->store('products', 'public');
        $galleryPath = UploadedFile::fake()->image('g1.jpg')->store('products/gallery', 'public');

        $product = Product::factory()->create(['thumbnail' => $thumbPath]);
        $product->images()->create(['path' => $galleryPath, 'sort_order' => 0]);

        $response = $this->deleteJson("/api/admin/products/{$product->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
        $this->publicDisk()->assertMissing($thumbPath);
        $this->publicDisk()->assertMissing($galleryPath);
    }

    public function test_admin_can_delete_single_gallery_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $product = Product::factory()->create();
        $imagePath = UploadedFile::fake()->image('g1.jpg')->store('products/gallery', 'public');
        $image = $product->images()->create(['path' => $imagePath, 'sort_order' => 0]);

        $response = $this->deleteJson("/api/admin/products/{$product->id}/images/{$image->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        $this->publicDisk()->assertMissing($imagePath);
    }

    public function test_cannot_delete_image_belonging_to_another_product(): void
    {
        $this->actingAsAdmin();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $image = $productB->images()->create(['path' => 'x.jpg', 'sort_order' => 0]);

        $response = $this->deleteJson("/api/admin/products/{$productA->id}/images/{$image->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }
}
