<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\InventoryBatch;
use App\Models\ProductionRecipe;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_can_be_registered_with_audited_initial_stock(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();

        $ingredient = $this->postJson('/api/ingredients', [
            'name' => 'Harina inicial',
            'base_unit_id' => $grams->id,
            'initial_stock' => 25000,
            'initial_lot_code' => 'HAR-001',
            'initial_expires_at' => today()->addMonths(3)->toDateString(),
            'initial_unit_cost' => 0.03,
        ])->assertCreated()->assertJsonPath('current_stock', '25000.0000')->json();

        $this->assertDatabaseHas('inventory_batches', ['ingredient_id' => $ingredient['id'], 'lot_code' => 'HAR-001', 'initial_quantity' => 25000, 'available_quantity' => 25000]);
        $this->assertDatabaseHas('inventory_adjustments', ['ingredient_id' => $ingredient['id'], 'reason' => 'initial', 'quantity' => 25000]);
        $this->assertDatabaseHas('inventory_movements', ['ingredient_id' => $ingredient['id'], 'type' => 'adjustment', 'reason' => 'initial', 'quantity' => 25000]);
    }

    public function test_purchase_converts_presentations_and_creates_inventory(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $kg = Unit::where('symbol', 'kg')->firstOrFail();
        $ingredient = $this->postJson('/api/ingredients', ['name' => 'Queso mozzarella', 'base_unit_id' => $grams->id, 'minimum_stock' => 2000, 'critical_stock' => 500])->assertCreated()->json();
        $presentation = $this->postJson("/api/ingredients/{$ingredient['id']}/presentations", ['name' => 'Bolsa 2 kg', 'quantity' => 2, 'equivalent_unit_id' => $kg->id])->assertCreated()->assertJsonPath('base_quantity', '2000.0000')->json();
        $this->postJson('/api/purchases', ['purchased_at' => today()->toDateString(), 'payment_source' => 'owner', 'items' => [['ingredient_presentation_id' => $presentation['id'], 'presentations_quantity' => 2, 'total_cost' => 400, 'expires_at' => today()->addDays(5)->toDateString(), 'lot_code' => 'Q-01']]])->assertCreated()->assertJsonPath('total', '400.00');
        $this->assertDatabaseHas('inventory_batches', ['ingredient_id' => $ingredient['id'], 'available_quantity' => 4000]);
        $this->assertDatabaseHas('inventory_movements', ['ingredient_id' => $ingredient['id'], 'type' => 'purchase', 'quantity' => 4000]);
    }

    public function test_purchase_presentations_can_be_recalculated_edited_and_deactivated(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $kilograms = Unit::where('symbol', 'kg')->firstOrFail();
        $ingredient = Ingredient::create([
            'branch_id' => $admin->branch_id,
            'base_unit_id' => $grams->id,
            'name' => 'Harina para presentación',
        ]);
        $presentation = $this->postJson("/api/ingredients/{$ingredient->id}/presentations", [
            'name' => 'Bolsa 1 kg',
            'quantity' => 1,
            'equivalent_unit_id' => $kilograms->id,
        ])->assertCreated()->assertJsonPath('base_quantity', '1000.0000')->json();

        $this->putJson("/api/ingredients/{$ingredient->id}/presentations/{$presentation['id']}", [
            'name' => 'Bolsa 2 kg',
            'quantity' => 2,
        ])->assertOk()
            ->assertJsonPath('name', 'Bolsa 2 kg')
            ->assertJsonPath('base_quantity', '2000.0000');

        $this->deleteJson("/api/ingredients/{$ingredient->id}/presentations/{$presentation['id']}")
            ->assertNoContent();
        $this->assertDatabaseHas('ingredient_presentations', ['id' => $presentation['id'], 'active' => false]);
    }

    public function test_fefo_uses_earliest_expiry_without_partial_changes_on_shortage(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Pepperoni', 'minimum_stock' => 100, 'critical_stock' => 20]);
        $later = InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'received_at' => today(), 'expires_at' => today()->addDays(10), 'initial_quantity' => 100, 'available_quantity' => 100]);
        $first = InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'received_at' => today(), 'expires_at' => today()->addDays(2), 'initial_quantity' => 80, 'available_quantity' => 80]);
        $service = app(InventoryService::class);
        $result = $service->consumeFefo($ingredient, 120, 'sale', $user);
        $this->assertSame(120.0, $result['consumed']);
        $this->assertEquals(0, $first->fresh()->available_quantity);
        $this->assertEquals(60, $later->fresh()->available_quantity);
        $short = $service->consumeFefo($ingredient, 100, 'sale', $user);
        $this->assertSame(40.0, $short['shortage']);
        $this->assertEquals(60, $later->fresh()->available_quantity);
    }

    public function test_suppliers_are_isolated_by_branch_when_listing_and_purchasing(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $otherBranch = Branch::create(['name' => 'Otra para compras', 'code' => 'OTRA-COMPRA']);
        $external = Supplier::create(['branch_id' => $otherBranch->id, 'name' => 'Proveedor externo']);
        $local = Supplier::create(['branch_id' => $admin->branch_id, 'name' => 'Proveedor local']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/catalogs/suppliers')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $local->id);

        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = $this->postJson('/api/ingredients', ['name' => 'Insumo compra', 'base_unit_id' => $grams->id])->assertCreated()->json();
        $presentation = $this->postJson("/api/ingredients/{$ingredient['id']}/presentations", ['name' => 'Bolsa', 'quantity' => 1000, 'equivalent_unit_id' => $grams->id])->assertCreated()->json();
        $this->postJson('/api/purchases', [
            'supplier_id' => $external->id,
            'purchased_at' => today()->toDateString(),
            'payment_source' => 'owner',
            'items' => [['ingredient_presentation_id' => $presentation['id'], 'presentations_quantity' => 1, 'total_cost' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_ingredient_base_unit_is_immutable_after_unit_dependent_activity(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $kilograms = Unit::where('symbol', 'kg')->firstOrFail();

        $unused = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Insumo sin actividad']);
        $this->patchJson("/api/ingredients/{$unused->id}", ['base_unit_id' => $kilograms->id])
            ->assertOk()
            ->assertJsonPath('base_unit_id', $kilograms->id);

        $withStock = $this->postJson('/api/ingredients', [
            'name' => 'Insumo con lote',
            'base_unit_id' => $grams->id,
            'initial_stock' => 10,
        ])->assertCreated()->json();

        $withPresentation = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Insumo con presentación']);
        $this->postJson("/api/ingredients/{$withPresentation->id}/presentations", [
            'name' => 'Bolsa',
            'quantity' => 1,
            'equivalent_unit_id' => $kilograms->id,
        ])->assertCreated();

        $productionInput = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Entrada de producción']);
        $productionOutput = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Salida de producción']);
        $recipe = ProductionRecipe::create([
            'branch_id' => $user->branch_id,
            'output_ingredient_id' => $productionOutput->id,
            'name' => 'Receta que fija unidades',
            'yield_quantity' => 100,
            'yield_unit_id' => $grams->id,
            'shelf_life_days' => 1,
        ]);
        $recipe->items()->create(['ingredient_id' => $productionInput->id, 'quantity' => 50]);

        foreach ([$withStock['id'], $withPresentation->id, $productionInput->id, $productionOutput->id] as $ingredientId) {
            $this->patchJson("/api/ingredients/{$ingredientId}", ['base_unit_id' => $kilograms->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('base_unit_id');
            $this->assertDatabaseHas('ingredients', ['id' => $ingredientId, 'base_unit_id' => $grams->id]);
        }
    }

    public function test_used_unit_keeps_dimension_and_factor_but_allows_descriptive_changes(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);

        $unused = Unit::create(['name' => 'Unidad libre', 'symbol' => 'ul', 'dimension' => 'mass', 'base_factor' => 2]);
        $this->putJson("/api/catalogs/units/{$unused->id}", ['dimension' => 'volume', 'base_factor' => 3])
            ->assertOk()
            ->assertJsonPath('dimension', 'volume')
            ->assertJsonPath('base_factor', '3.000000');

        $used = Unit::create(['name' => 'Unidad usada', 'symbol' => 'uu', 'dimension' => 'mass', 'base_factor' => 1]);
        Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $used->id, 'name' => 'Insumo que usa unidad']);

        $this->putJson("/api/catalogs/units/{$used->id}", ['dimension' => 'volume'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dimension');
        $this->putJson("/api/catalogs/units/{$used->id}", ['base_factor' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_factor');
        $this->putJson("/api/catalogs/units/{$used->id}", ['name' => 'Unidad usada renombrada'])
            ->assertOk()
            ->assertJsonPath('name', 'Unidad usada renombrada');

        $this->assertDatabaseHas('units', ['id' => $used->id, 'dimension' => 'mass', 'base_factor' => 1]);
    }

    public function test_inventory_adjustment_reason_enforces_quantity_direction(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Insumo para ajustes']);
        $batch = InventoryBatch::create([
            'branch_id' => $user->branch_id,
            'ingredient_id' => $ingredient->id,
            'received_at' => today(),
            'initial_quantity' => 100,
            'available_quantity' => 100,
        ]);

        foreach (['waste', 'expiry', 'preparation_error', 'gift', 'internal_use', 'loss'] as $reason) {
            $this->postJson('/api/inventory/adjustments', [
                'inventory_batch_id' => $batch->id,
                'quantity' => 5,
                'reason' => $reason,
            ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->postJson('/api/inventory/adjustments', [
            'inventory_batch_id' => $batch->id,
            'quantity' => -5,
            'reason' => 'initial',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->postJson('/api/inventory/adjustments', [
            'inventory_batch_id' => $batch->id,
            'quantity' => -10,
            'reason' => 'waste',
        ])->assertCreated();
        $this->postJson('/api/inventory/adjustments', [
            'inventory_batch_id' => $batch->id,
            'quantity' => 5,
            'reason' => 'initial',
        ])->assertCreated();

        $this->assertEquals(95, $batch->fresh()->available_quantity);
        $this->assertDatabaseCount('inventory_adjustments', 2);
    }

    public function test_purchase_rejects_future_or_incoherent_dates(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Insumo con fechas']);
        $presentation = IngredientPresentation::create(['ingredient_id' => $ingredient->id, 'name' => 'Bolsa', 'quantity' => 100, 'equivalent_unit_id' => $grams->id, 'base_quantity' => 100]);

        $this->postJson('/api/purchases', [
            'purchased_at' => today()->addDay()->toDateString(),
            'payment_source' => 'owner',
            'items' => [['ingredient_presentation_id' => $presentation->id, 'presentations_quantity' => 1, 'total_cost' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors('purchased_at');

        $this->postJson('/api/purchases', [
            'purchased_at' => today()->toDateString(),
            'payment_source' => 'owner',
            'items' => [[
                'ingredient_presentation_id' => $presentation->id,
                'presentations_quantity' => 1,
                'total_cost' => 50,
                'expires_at' => today()->subDay()->toDateString(),
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.expires_at');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_purchase_requires_active_local_and_unit_compatible_references(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $milliliters = Unit::where('symbol', 'ml')->firstOrFail();
        $localIngredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Ingrediente local válido']);
        $localPresentation = IngredientPresentation::create(['ingredient_id' => $localIngredient->id, 'name' => 'Bolsa local', 'quantity' => 100, 'equivalent_unit_id' => $grams->id, 'base_quantity' => 100]);
        $payload = fn (int $presentationId): array => [
            'purchased_at' => today()->toDateString(),
            'payment_source' => 'owner',
            'items' => [['ingredient_presentation_id' => $presentationId, 'presentations_quantity' => 1, 'total_cost' => 50]],
        ];

        $inactiveSupplier = Supplier::create(['branch_id' => $user->branch_id, 'name' => 'Proveedor inactivo', 'active' => false]);
        $withSupplier = $payload($localPresentation->id);
        $withSupplier['supplier_id'] = $inactiveSupplier->id;
        $this->postJson('/api/purchases', $withSupplier)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');

        $inactivePresentation = IngredientPresentation::create(['ingredient_id' => $localIngredient->id, 'name' => 'Bolsa inactiva', 'quantity' => 100, 'equivalent_unit_id' => $grams->id, 'base_quantity' => 100, 'active' => false]);
        $this->postJson('/api/purchases', $payload($inactivePresentation->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.ingredient_presentation_id');

        $inactiveIngredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Ingrediente inactivo', 'active' => false]);
        $inactiveIngredientPresentation = IngredientPresentation::create(['ingredient_id' => $inactiveIngredient->id, 'name' => 'Bolsa de inactivo', 'quantity' => 100, 'equivalent_unit_id' => $grams->id, 'base_quantity' => 100]);
        $this->postJson('/api/purchases', $payload($inactiveIngredientPresentation->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.ingredient_presentation_id');

        $otherBranch = Branch::create(['name' => 'Sucursal externa para presentación', 'code' => 'EXT-PRES']);
        $externalIngredient = Ingredient::create(['branch_id' => $otherBranch->id, 'base_unit_id' => $grams->id, 'name' => 'Ingrediente externo']);
        $externalPresentation = IngredientPresentation::create(['ingredient_id' => $externalIngredient->id, 'name' => 'Bolsa externa', 'quantity' => 100, 'equivalent_unit_id' => $grams->id, 'base_quantity' => 100]);
        $this->postJson('/api/purchases', $payload($externalPresentation->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.ingredient_presentation_id');

        $incompatible = IngredientPresentation::create(['ingredient_id' => $localIngredient->id, 'name' => 'Presentación incompatible', 'quantity' => 100, 'equivalent_unit_id' => $milliliters->id, 'base_quantity' => 100]);
        $this->postJson('/api/purchases', $payload($incompatible->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.ingredient_presentation_id');

        $this->assertDatabaseCount('purchases', 0);
    }
}
