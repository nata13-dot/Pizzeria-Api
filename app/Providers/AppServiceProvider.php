<?php

namespace App\Providers;

use App\Models\BusinessProfile;
use App\Models\CashDay;
use App\Models\CashMovement;
use App\Models\Combo;
use App\Models\ComboAllowedOption;
use App\Models\ComboItem;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\InventoryAdjustment;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Modifier;
use App\Models\ModifierRecipeItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFlavor;
use App\Models\ProductionBatch;
use App\Models\ProductionRecipe;
use App\Models\ProductModifierRule;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\Role;
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
        foreach ([
            User::class,
            Role::class,
            BusinessProfile::class,
            Setting::class,
            Ingredient::class,
            IngredientPresentation::class,
            InventoryAdjustment::class,
            Purchase::class,
            ProductionRecipe::class,
            ProductionBatch::class,
            ProductCategory::class,
            Product::class,
            ProductVariant::class,
            ProductFlavor::class,
            Recipe::class,
            Modifier::class,
            ModifierRecipeItem::class,
            ProductModifierRule::class,
            Combo::class,
            ComboItem::class,
            ComboAllowedOption::class,
            Order::class,
            Customer::class,
            LoyaltyRule::class,
            LoyaltyTransaction::class,
            LoyaltyRedemption::class,
            CashDay::class,
            CashMovement::class,
        ] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
