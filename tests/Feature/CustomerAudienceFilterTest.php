<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CustomerAudienceFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAudienceFilterTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAudienceFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new CustomerAudienceFilter();
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'phone' => '0912' . rand(1000000, 9999999),
        ], $attributes));
    }

    public function test_returns_only_customers_with_phone(): void
    {
        $withPhone = $this->customer();
        $withoutPhone = $this->customer(['phone' => null]);

        $ids = $this->filter->buildQuery([])->pluck('id');

        $this->assertTrue($ids->contains($withPhone->id));
        $this->assertFalse($ids->contains($withoutPhone->id));
    }

    public function test_excludes_non_customer_roles(): void
    {
        $customer = $this->customer();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '09121111111']);

        $ids = $this->filter->buildQuery([])->pluck('id');

        $this->assertTrue($ids->contains($customer->id));
        $this->assertFalse($ids->contains($admin->id));
    }

    public function test_filters_by_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create();
        $withVehicle = $this->customer();
        $withVehicle->vehicles()->attach($vehicle->id);

        $withoutVehicle = $this->customer();

        $ids = $this->filter->buildQuery(['vehicle_id' => $vehicle->id])->pluck('id');

        $this->assertTrue($ids->contains($withVehicle->id));
        $this->assertFalse($ids->contains($withoutVehicle->id));
    }

    public function test_filters_by_purchased_product(): void
    {
        $product = Product::factory()->create();

        $buyer = $this->customer();
        $order = Order::factory()->create(['user_id' => $buyer->id, 'status' => Order::STATUS_PAID]);
        $order->items()->create(['product_id' => $product->id, 'title' => 'کالا', 'sku' => 'S1', 'price' => 1000, 'quantity' => 1]);

        $nonBuyer = $this->customer();

        $ids = $this->filter->buildQuery(['purchased_product_id' => $product->id])->pluck('id');

        $this->assertTrue($ids->contains($buyer->id));
        $this->assertFalse($ids->contains($nonBuyer->id));
    }

    public function test_purchased_product_ignores_unpaid_orders(): void
    {
        $product = Product::factory()->create();

        $customer = $this->customer();
        $order = Order::factory()->create(['user_id' => $customer->id, 'status' => Order::STATUS_PENDING_REVIEW]);
        $order->items()->create(['product_id' => $product->id, 'title' => 'کالا', 'sku' => 'S1', 'price' => 1000, 'quantity' => 1]);

        $ids = $this->filter->buildQuery(['purchased_product_id' => $product->id])->pluck('id');

        $this->assertFalse($ids->contains($customer->id));
    }

    public function test_filters_by_has_purchased_true(): void
    {
        $buyer = $this->customer();
        Order::factory()->create(['user_id' => $buyer->id, 'status' => Order::STATUS_PAID]);

        $nonBuyer = $this->customer();

        $ids = $this->filter->buildQuery(['has_purchased' => true])->pluck('id');

        $this->assertTrue($ids->contains($buyer->id));
        $this->assertFalse($ids->contains($nonBuyer->id));
    }

    public function test_filters_by_has_purchased_false(): void
    {
        $buyer = $this->customer();
        Order::factory()->create(['user_id' => $buyer->id, 'status' => Order::STATUS_PAID]);

        $nonBuyer = $this->customer();

        $ids = $this->filter->buildQuery(['has_purchased' => false])->pluck('id');

        $this->assertFalse($ids->contains($buyer->id));
        $this->assertTrue($ids->contains($nonBuyer->id));
    }

    public function test_filters_by_no_purchase_since(): void
    {
        $recentBuyer = $this->customer();
        Order::factory()->create([
            'user_id' => $recentBuyer->id,
            'status' => Order::STATUS_PAID,
            'paid_at' => now()->subDays(2),
        ]);

        $oldBuyer = $this->customer();
        Order::factory()->create([
            'user_id' => $oldBuyer->id,
            'status' => Order::STATUS_PAID,
            'paid_at' => now()->subDays(60),
        ]);

        $neverBought = $this->customer();

        $ids = $this->filter->buildQuery(['no_purchase_since' => now()->subDays(30)->toDateString()])->pluck('id');

        // کسی که اخیراً خریده نباید توی لیست باشه
        $this->assertFalse($ids->contains($recentBuyer->id));
        // کسی که خیلی وقته خرید نکرده و کسی که اصلاً خرید نکرده، باید باشن
        $this->assertTrue($ids->contains($oldBuyer->id));
        $this->assertTrue($ids->contains($neverBought->id));
    }

    public function test_filters_by_city(): void
    {
        $tehranCustomer = $this->customer(['city' => 'تهران']);
        $mashhadCustomer = $this->customer(['city' => 'مشهد']);

        $ids = $this->filter->buildQuery(['city' => 'تهران'])->pluck('id');

        $this->assertTrue($ids->contains($tehranCustomer->id));
        $this->assertFalse($ids->contains($mashhadCustomer->id));
    }

    public function test_combines_multiple_filters_with_and(): void
    {
        $vehicle = Vehicle::factory()->create();

        $matches = $this->customer(['city' => 'تهران']);
        $matches->vehicles()->attach($vehicle->id);

        $wrongCity = $this->customer(['city' => 'مشهد']);
        $wrongCity->vehicles()->attach($vehicle->id);

        $noVehicle = $this->customer(['city' => 'تهران']);

        $ids = $this->filter->buildQuery([
            'vehicle_id' => $vehicle->id,
            'city' => 'تهران',
        ])->pluck('id');

        $this->assertTrue($ids->contains($matches->id));
        $this->assertFalse($ids->contains($wrongCity->id));
        $this->assertFalse($ids->contains($noVehicle->id));
    }

    public function test_empty_filters_return_all_customers_with_phone(): void
    {
        $a = $this->customer();
        $b = $this->customer();

        $ids = $this->filter->buildQuery([])->pluck('id');

        $this->assertTrue($ids->contains($a->id));
        $this->assertTrue($ids->contains($b->id));
    }
}
