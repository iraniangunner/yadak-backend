<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Article;
use App\Models\Banner;
use App\Contracts\ShippingProviderContract;
use App\Services\Shipping\AggregateShippingProvider;
use App\Contracts\AccountingProviderContract;
use App\Services\Accounting\SepidarAccountingProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
      $this->app->bind(ShippingProviderContract::class, AggregateShippingProvider::class);
      $this->app->bind(AccountingProviderContract::class, SepidarAccountingProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(14));

        Relation::enforceMorphMap([
            'product' => Product::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'order' => Order::class,
            'order_return' => OrderReturn::class,
            'article' => Article::class,
            'banner' => Banner::class,
        ]);
    }
}
