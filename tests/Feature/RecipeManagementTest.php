<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductFlavor;
use App\Models\ProductionRecipe;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $this->seed();
        $user = User::first();
        Sanctum::actingAs($user);
        $unit = Unit::where('symbol', 'g')->first();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Masa P16']);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Pizza P16', 'type' => 'pizza']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Mediana', 'price' => 100]);

        return [$user, $ingredient, $product, $variant];
    }

    public function test_directory_searches_all_records_and_enforces_branch_and_pages(): void
    {
        [$user, $ingredient, $product, $variant] = $this->fixtures();
        foreach (range(1, 30) as $n) {
            $v = ProductVariant::create(['product_id' => $product->id, 'name' => sprintf('Tamaño %02d', $n), 'price' => 10]);
            Recipe::create(['product_variant_id' => $v->id, 'name' => sprintf('Receta %02d', $n)]);
        }
        $this->getJson('/api/recipes?search=Receta%2030')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Receta 30');
        $this->getJson('/api/recipes?search=0')->assertOk()->assertJsonPath('total', 12);
        $first = $this->getJson('/api/recipes?page=1')->assertOk()->json('data');
        $second = $this->getJson('/api/recipes?page=2')->assertOk()->json('data');
        $this->assertCount(25, $first);
        $this->assertCount(5, $second);
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->getJson('/api/recipes/destinations?search=Tamaño%2030')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/recipes?page=0')->assertUnprocessable();
        $other = Branch::create(['name' => 'Other', 'code' => 'P16OTHER']);
        $product->update(['branch_id' => $other->id]);
        $this->getJson('/api/recipes')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/recipes/destinations')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/recipes/destinations/'.$variant->id)->assertNotFound();
    }

    public function test_edit_clone_duplicates_units_and_recipe_to_sale_inventory_consumption(): void
    {
        [$user, $ingredient, $product, $variant] = $this->fixtures();
        $flavor = ProductFlavor::create(['product_id' => $product->id, 'name' => 'Pepperoni']);
        $payload = ['product_variant_id' => $variant->id, 'product_flavor_id' => $flavor->id, 'name' => 'Mediana Pepperoni', 'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 1.5, 'component' => 'base']]];
        $id = $this->postJson('/api/recipes', $payload)->assertCreated()->json('id');
        $this->getJson('/api/recipes/'.$id)->assertOk()->assertJsonPath('items.0.ingredient.base_unit.symbol', 'g');
        $this->getJson('/api/recipes?search=Pepperoni')->assertJsonPath('total', 1);
        $this->postJson('/api/recipes', $payload)->assertUnprocessable();
        $this->postJson('/api/recipes/'.$id.'/clone', ['product_variant_id' => $variant->id, 'product_flavor_id' => $flavor->id, 'name' => 'Copia'])->assertUnprocessable();
        $this->putJson('/api/recipes/'.$id, ['product_variant_id' => $variant->id])->assertUnprocessable();
        $this->putJson('/api/recipes/'.$id, ['items' => [$payload['items'][0], $payload['items'][0]]])->assertUnprocessable();
        $this->putJson('/api/recipes/'.$id, ['items' => [['ingredient_id' => $ingredient->id, 'quantity' => 2.5, 'component' => 'base']]])->assertOk();
        $this->assertDatabaseCount('recipes', 1);
        $lot = InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'received_at' => today(), 'initial_quantity' => 20, 'available_quantity' => 20]);
        $order = $this->postJson('/api/orders', ['idempotency_key' => 'p16-recipe-inventory', 'status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Ana', 'contact_phone' => '5551234567', 'items' => [['product_variant_id' => $variant->id, 'flavor_ids' => [$flavor->id], 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 200]]])->assertCreated()->json('id');
        $this->postJson('/api/orders/'.$order.'/send-to-kitchen')->assertOk();
        $this->assertEquals(15, $lot->fresh()->available_quantity);
        $this->assertEquals(-5, InventoryMovement::where('ingredient_id', $ingredient->id)->sum('quantity'));
        $this->deleteJson('/api/recipes/'.$id)->assertNoContent();
        $this->postJson('/api/recipes', $payload)->assertUnprocessable();
        $this->putJson('/api/recipes/'.$id, ['active' => true])->assertOk();
    }

    public function test_production_lookup_is_paginated_without_changing_legacy_array_contract(): void
    {
        [$user, $ingredient] = $this->fixtures();
        foreach (range(1, 30) as $n) {
            ProductionRecipe::create(['branch_id' => $user->branch_id, 'name' => sprintf('Preparación %02d', $n), 'output_ingredient_id' => $ingredient->id, 'yield_quantity' => 10, 'yield_unit_id' => $ingredient->base_unit_id, 'shelf_life_days' => 1]);
        }
        $this->getJson('/api/production-recipes')->assertOk()->assertJsonCount(30);
        $this->getJson('/api/production-recipes?paginated=1&page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('total', 30);
        $found = $this->getJson('/api/production-recipes?paginated=1&search=Preparación%2030')->assertOk()->assertJsonPath('total', 1)->json('data.0.id');
        $this->getJson('/api/production-recipes/'.$found)->assertOk()->assertJsonPath('output_ingredient.base_unit.symbol', 'g');
        $this->getJson('/api/production-recipes?paginated=1&active=0')->assertJsonPath('total', 0);
        $this->getJson('/api/production-recipes?paginated=1&page=0')->assertUnprocessable();
        $other = Branch::create(['name' => 'Otra producción', 'code' => 'P16PROD']);
        ProductionRecipe::whereKey($found)->update(['branch_id' => $other->id]);
        $this->getJson('/api/production-recipes/'.$found)->assertNotFound();
        $this->getJson('/api/production-recipes?paginated=1&search=Preparación%2030')->assertJsonPath('total', 0);
        $cashier = User::whereHas('role', fn ($q) => $q->where('slug', 'cajero'))->firstOrFail();
        Sanctum::actingAs($cashier);
        $this->getJson('/api/recipes')->assertForbidden();
        $this->getJson('/api/recipes/destinations')->assertForbidden();
    }
}
