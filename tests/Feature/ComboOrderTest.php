<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComboOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_combo_uses_fixed_component_quantities_and_price(): void
    {
        $this->seed();
        $u = User::first();
        Sanctum::actingAs($u);
        $g = Unit::where('symbol', 'g')->first();
        $cheese = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Queso']);
        InventoryBatch::create(['branch_id' => $u->branch_id, 'ingredient_id' => $cheese->id, 'received_at' => today(), 'initial_quantity' => 1000, 'available_quantity' => 1000]);
        $product = Product::create(['branch_id' => $u->branch_id, 'name' => 'Pizza', 'type' => 'pizza']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Grande', 'price' => 200]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Base']);
        $recipe->items()->create(['ingredient_id' => $cheese->id, 'quantity' => 100, 'component' => 'base']);
        $combo = Combo::create(['branch_id' => $u->branch_id, 'name' => 'Doble pizza', 'price' => 350]);
        $component = $combo->items()->create(['product_variant_id' => $variant->id, 'quantity' => 2]);
        $order = $this->postJson('/api/orders', ['status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567', 'items' => [['combo_id' => $combo->id, 'quantity' => 1, 'components' => [['combo_item_id' => $component->id]]]], 'payments' => [['method' => 'cash', 'amount' => 350]]])->assertCreated()->assertJsonPath('total', 350)->assertJsonPath('items.0.components.0.name', 'Pizza Grande')->assertJsonPath('items.0.components.0.quantity', 2)->json();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertOk();
        $this->assertEquals(800, $cheese->batches()->sum('available_quantity'));
    }

    public function test_combo_accepts_an_independent_selection_for_each_component_unit(): void
    {
        $this->seed();
        $user = User::first();
        Sanctum::actingAs($user);
        $gram = Unit::where('symbol', 'g')->first();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $gram->id, 'name' => 'Cono']);
        InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'received_at' => today(), 'initial_quantity' => 1000, 'available_quantity' => 1000]);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Cono pizza', 'type' => 'cone']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Individual', 'price' => 50]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Cono base']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 25, 'component' => 'base']);
        $combo = Combo::create(['branch_id' => $user->branch_id, 'name' => 'Cuatro conos', 'price' => 180]);
        $component = $combo->items()->create(['product_variant_id' => $variant->id, 'quantity' => 4]);
        $components = collect(range(1, 4))->map(fn (int $unit) => [
            'combo_item_id' => $component->id,
            'unit_index' => $unit,
            'notes' => "Cono {$unit}",
        ])->all();

        $this->postJson('/api/orders', [
            'status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'items' => [['combo_id' => $combo->id, 'quantity' => 1, 'components' => $components]],
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ])->assertCreated()
            ->assertJsonCount(4, 'items.0.components')
            ->assertJsonPath('items.0.components.0.notes', 'Cono 1')
            ->assertJsonPath('items.0.components.3.notes', 'Cono 4');
    }
}
