<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryPurchaseExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_inventory_summary_search_status_detail_and_origins_are_operational(): void
    {
        $user = $this->admin();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $available = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Harina operativa', 'sku' => 'HAR-OP', 'minimum_stock' => 500, 'critical_stock' => 100]);
        $low = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Queso operativo', 'minimum_stock' => 1000, 'critical_stock' => 200, 'expiry_alert_days' => 3]);
        $out = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Pepperoni operativo', 'minimum_stock' => 300, 'critical_stock' => 50]);
        $today = app(BranchClock::class)->today($user->branch_id)->toDateString();
        foreach ([[$available, 1200], [$low, 850]] as [$ingredient, $quantity]) {
            $batch = InventoryBatch::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'received_at' => $today, 'expires_at' => $ingredient->is($low) ? app(BranchClock::class)->today($user->branch_id)->addDay()->toDateString() : null, 'initial_quantity' => $quantity, 'available_quantity' => $quantity, 'unit_cost' => 1]);
            InventoryMovement::create(['branch_id' => $user->branch_id, 'ingredient_id' => $ingredient->id, 'inventory_batch_id' => $batch->id, 'user_id' => $user->id, 'type' => 'adjustment', 'quantity' => $quantity, 'quantity_before' => 0, 'quantity_after' => $quantity, 'reason' => 'initial']);
        }

        $this->getJson('/api/inventory/summary')->assertOk()->assertJsonPath('available', fn ($value) => $value >= 1)->assertJsonPath('low', fn ($value) => $value >= 1)->assertJsonPath('out', fn ($value) => $value >= 1);
        $this->getJson('/api/inventory/items?search=HAR-OP')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_status', 'available');
        $this->getJson('/api/inventory/items?status=low&search=operativo')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Queso operativo')->assertJsonPath('data.0.missing_to_minimum', fn ($value) => (float) $value === 150.0);
        $this->getJson('/api/inventory/items?status=out&search=Pepperoni')->assertOk()->assertJsonPath('data.0.stock_status', 'out');
        $this->getJson('/api/inventory/items/'.$available->id)->assertOk()->assertJsonPath('movements.0.type_label', 'Ajuste')->assertJsonPath('movements.0.origin_label', null);
        $this->getJson('/api/inventory/batches?status=expiring')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ingredient_id', $low->id);
        $this->getJson('/api/inventory/movements?search=Harina')->assertOk()->assertJsonPath('data.0.type_label', 'Ajuste');
    }

    public function test_purchase_revision_conversion_and_atomic_inventory_effect(): void
    {
        $user = $this->admin();
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $kilograms = Unit::where('symbol', 'kg')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Harina compra']);
        $presentation = $this->postJson('/api/ingredients/'.$ingredient->id.'/presentations', ['name' => 'Bolsa 1 kg', 'quantity' => 1, 'equivalent_unit_id' => $kilograms->id])->assertCreated()->json();
        $detail = $this->getJson('/api/ingredients/'.$ingredient->id)->assertOk();
        $revision = $detail->json('presentations.0.purchase_revision');
        $payload = ['purchased_at' => app(BranchClock::class)->today($user->branch_id)->toDateString(), 'payment_source' => 'owner', 'items' => [[
            'ingredient_presentation_id' => $presentation['id'], 'presentations_quantity' => 2, 'total_cost' => '90.00', 'expected_presentation_revision' => $revision,
        ]]];
        $purchase = $this->postJson('/api/purchases', $payload)->assertCreated()->assertJsonPath('total', '90.00');
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->json('id'), 'base_quantity' => 2000]);
        $this->assertDatabaseHas('inventory_batches', ['ingredient_id' => $ingredient->id, 'initial_quantity' => 2000, 'available_quantity' => 2000]);
        $this->assertDatabaseHas('inventory_movements', ['ingredient_id' => $ingredient->id, 'type' => 'purchase', 'quantity' => 2000]);
        $this->getJson('/api/inventory/items/'.$ingredient->id)->assertOk()->assertJsonPath('current_stock', fn ($value) => (float) $value === 2000.0)->assertJsonPath('movements.0.type_label', 'Compra')->assertJsonPath('movements.0.origin_label', 'Compra #'.$purchase->json('id'));

        $this->putJson('/api/ingredients/'.$ingredient->id.'/presentations/'.$presentation['id'], ['name' => 'Bolsa 2 kg', 'quantity' => 2, 'equivalent_unit_id' => $kilograms->id, 'active' => true])->assertOk();
        $this->postJson('/api/purchases', $payload)->assertStatus(409)->assertJsonPath('code', 'presentation_changed');
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('inventory_batches', 1);
    }
}
