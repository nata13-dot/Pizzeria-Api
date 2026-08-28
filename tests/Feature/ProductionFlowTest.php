<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\ProductionRecipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_consumes_inputs_and_creates_dough_batch(): void
    {
        $this->seed();
        $user = User::first();
        Sanctum::actingAs($user);
        $g = Unit::where('symbol', 'g')->first();
        $flour = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $g->id, 'name' => 'Harina']);
        $dough = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $g->id, 'name' => 'Masa base']);
        InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $flour->id, 'received_at' => today(), 'initial_quantity' => 5000, 'available_quantity' => 5000, 'unit_cost' => 2]);
        $recipe = $this->postJson('/api/production-recipes', ['name' => 'Masa base', 'output_ingredient_id' => $dough->id, 'yield_quantity' => 1000, 'yield_unit_id' => $g->id, 'shelf_life_days' => 1, 'items' => [['ingredient_id' => $flour->id, 'quantity' => 600]]])->assertCreated()->assertJsonPath('output_ingredient.id', $dough->id)->json();
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 2])->assertCreated()->assertJsonPath('recipe.output_ingredient_id', $dough->id);
        $this->assertEquals(3800, $flour->batches()->sum('available_quantity'));
        $this->assertEquals(2000, $dough->batches()->sum('available_quantity'));
        $this->assertEquals(1.2, (float) $dough->batches()->value('unit_cost'));
    }

    public function test_failed_production_rolls_back_all_inputs(): void
    {
        $this->seed();
        $u = User::first();
        Sanctum::actingAs($u);
        $g = Unit::where('symbol', 'g')->first();
        $a = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Harina']);
        $b = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Levadura']);
        $out = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Masa']);
        InventoryBatch::create(['branch_id' => $u->branch_id, 'ingredient_id' => $a->id, 'received_at' => today(), 'initial_quantity' => 1000, 'available_quantity' => 1000]);
        $recipe = $this->postJson('/api/production-recipes', ['name' => 'Masa', 'output_ingredient_id' => $out->id, 'yield_quantity' => 1000, 'yield_unit_id' => $g->id, 'shelf_life_days' => 1, 'items' => [['ingredient_id' => $a->id, 'quantity' => 600], ['ingredient_id' => $b->id, 'quantity' => 10]]])->assertCreated()->json();
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 1])->assertUnprocessable();
        $this->assertEquals(1000, $a->batches()->sum('available_quantity'));
        $this->assertDatabaseCount('production_batches', 0);
    }

    public function test_production_converts_recipe_yield_to_output_base_unit(): void
    {
        $this->seed();
        $u = User::first();
        Sanctum::actingAs($u);
        $g = Unit::where('symbol', 'g')->first();
        $kg = Unit::where('symbol', 'kg')->first();
        $flour = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Harina conversión']);
        $dough = Ingredient::create(['branch_id' => $u->branch_id, 'base_unit_id' => $g->id, 'name' => 'Masa conversión']);
        InventoryBatch::create(['branch_id' => $u->branch_id, 'ingredient_id' => $flour->id, 'received_at' => today(), 'initial_quantity' => 2000, 'available_quantity' => 2000]);
        $recipe = $this->postJson('/api/production-recipes', ['name' => 'Masa kg', 'output_ingredient_id' => $dough->id, 'yield_quantity' => 1, 'yield_unit_id' => $kg->id, 'shelf_life_days' => 1, 'items' => [['ingredient_id' => $flour->id, 'quantity' => 600]]])->assertCreated()->json();
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 2])->assertCreated();
        $this->assertEquals(2000, $dough->batches()->sum('available_quantity'));
    }

    public function test_recipe_output_must_be_active_local_and_dimension_compatible_on_create_and_update(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $milliliters = Unit::where('symbol', 'ml')->firstOrFail();
        $flour = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Harina validación']);
        $dough = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Masa validación']);
        $inactive = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Salida inactiva', 'active' => false]);
        $liquid = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $milliliters->id, 'name' => 'Salida líquida']);
        $otherBranch = Branch::create(['name' => 'Otra producción', 'code' => 'OTRA-PROD']);
        $external = Ingredient::create(['branch_id' => $otherBranch->id, 'base_unit_id' => $grams->id, 'name' => 'Salida externa']);

        $payload = [
            'name' => 'Receta validada',
            'yield_quantity' => 1000,
            'yield_unit_id' => $grams->id,
            'shelf_life_days' => 1,
            'items' => [['ingredient_id' => $flour->id, 'quantity' => 600]],
        ];

        $this->postJson('/api/production-recipes', $payload + ['output_ingredient_id' => $external->id])
            ->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');
        $this->postJson('/api/production-recipes', $payload + ['output_ingredient_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');
        $this->postJson('/api/production-recipes', $payload + ['output_ingredient_id' => $liquid->id])
            ->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');

        $recipe = $this->postJson('/api/production-recipes', $payload + ['output_ingredient_id' => $dough->id])
            ->assertCreated()->json();
        $this->putJson("/api/production-recipes/{$recipe['id']}", $payload + ['output_ingredient_id' => $external->id])
            ->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');
        $this->assertDatabaseHas('production_recipes', ['id' => $recipe['id'], 'output_ingredient_id' => $dough->id]);
    }

    public function test_recipe_rejects_inactive_or_external_inputs(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $dough = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Masa entradas']);
        $inactive = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Entrada inactiva', 'active' => false]);
        $otherBranch = Branch::create(['name' => 'Otra entradas', 'code' => 'OTRA-INPUT']);
        $external = Ingredient::create(['branch_id' => $otherBranch->id, 'base_unit_id' => $grams->id, 'name' => 'Entrada externa']);
        $base = [
            'name' => 'Receta entradas',
            'output_ingredient_id' => $dough->id,
            'yield_quantity' => 1000,
            'yield_unit_id' => $grams->id,
            'shelf_life_days' => 1,
        ];

        $this->postJson('/api/production-recipes', $base + ['items' => [['ingredient_id' => $inactive->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/production-recipes', $base + ['items' => [['ingredient_id' => $external->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_production_cannot_override_recipe_output_or_declare_impossible_portions(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $flour = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Harina porciones']);
        $dough = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Masa porciones']);
        $otherOutput = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Salida incorrecta']);
        InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $flour->id, 'received_at' => today(), 'initial_quantity' => 5000, 'available_quantity' => 5000]);
        $recipe = $this->postJson('/api/production-recipes', [
            'name' => 'Masa porcionada',
            'output_ingredient_id' => $dough->id,
            'yield_quantity' => 1000,
            'yield_unit_id' => $grams->id,
            'shelf_life_days' => 1,
            'items' => [['ingredient_id' => $flour->id, 'quantity' => 600]],
        ])->assertCreated()->json();

        $this->postJson('/api/production-batches', [
            'production_recipe_id' => $recipe['id'],
            'output_ingredient_id' => $otherOutput->id,
            'multiplier' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');
        $this->postJson('/api/production-batches', [
            'production_recipe_id' => $recipe['id'],
            'multiplier' => 1,
            'produced_at' => now()->addMinute()->toISOString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('produced_at');
        $this->postJson('/api/production-batches', [
            'production_recipe_id' => $recipe['id'],
            'multiplier' => 1,
            'outputs' => [['portion_name' => 'Grande', 'quantity' => 5, 'grams_per_portion' => 250]],
        ])->assertUnprocessable()->assertJsonValidationErrors('outputs');

        $this->postJson('/api/production-batches', [
            'production_recipe_id' => $recipe['id'],
            'output_ingredient_id' => $dough->id,
            'multiplier' => 1,
            'outputs' => [['portion_name' => 'Grande', 'quantity' => 4, 'grams_per_portion' => 250]],
        ])->assertCreated();
        $this->assertEquals(1000, $dough->batches()->sum('available_quantity'));
        $this->assertDatabaseHas('production_batch_outputs', [
            'ingredient_id' => $dough->id,
            'portion_name' => 'Grande',
            'quantity' => 4,
            'grams_per_portion' => 250,
        ]);
    }

    public function test_production_revalidates_recipe_inputs_output_and_active_state(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $flour = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Harina revalidación']);
        $dough = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Masa revalidación']);
        InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $flour->id, 'received_at' => today(), 'initial_quantity' => 5000, 'available_quantity' => 5000]);
        $recipe = $this->postJson('/api/production-recipes', [
            'name' => 'Masa revalidada',
            'output_ingredient_id' => $dough->id,
            'yield_quantity' => 1000,
            'yield_unit_id' => $grams->id,
            'shelf_life_days' => 1,
            'items' => [['ingredient_id' => $flour->id, 'quantity' => 600]],
        ])->assertCreated()->json();

        $flour->update(['active' => false]);
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $flour->update(['active' => true]);
        $dough->update(['active' => false]);
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('output_ingredient_id');
        $dough->update(['active' => true]);
        ProductionRecipe::whereKey($recipe['id'])->update(['active' => false]);
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('production_recipe_id');

        $this->assertDatabaseCount('production_batches', 0);
        $this->assertEquals(5000, $flour->batches()->sum('available_quantity'));
    }
}
