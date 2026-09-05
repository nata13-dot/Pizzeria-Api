<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_read_safe_settings_and_disabled_payment_method_is_rejected(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        Setting::create([
            'branch_id' => $cashier->branch_id,
            'key' => 'payment_methods',
            'value' => [
                ['key' => 'cash', 'label' => 'Efectivo', 'active' => true],
                ['key' => 'transfer', 'label' => 'Transferencia', 'active' => false],
            ],
        ]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/operational-settings')
            ->assertOk()
            ->assertJsonPath('payment_methods.1.active', false)
            ->assertJsonMissing(['show_kitchen_prices' => false]);

        $variant = $this->variant($cashier);
        $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'transfer', 'amount' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');
    }

    private function variant(User $user): ProductVariant
    {
        $unit = Unit::where('symbol', 'pz')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Insumo ajustes']);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Producto ajustes', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 100]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Receta ajustes']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'component' => 'base']);

        return $variant;
    }
}
