<?php

namespace App\Providers;

use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([User::class, Ingredient::class, Purchase::class, Product::class, Recipe::class, Order::class, InventoryAdjustment::class, LoyaltyTransaction::class, Setting::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
