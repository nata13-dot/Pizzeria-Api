<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_dates_use_the_branch_day_near_utc_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 01:00:00 UTC'));
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $pieces = Unit::where('symbol', 'pz')->firstOrFail();

        $created = $this->postJson('/api/ingredients', [
            'name' => 'Insumo horario local',
            'base_unit_id' => $pieces->id,
            'initial_stock' => 2,
        ])->assertCreated()->json();
        $this->assertDatabaseHas('inventory_batches', [
            'ingredient_id' => $created['id'],
            'received_at' => '2026-08-25 00:00:00',
        ]);

        $ingredient = Ingredient::findOrFail($created['id']);
        InventoryBatch::create([
            'branch_id' => $admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'received_at' => '2026-08-24',
            'expires_at' => '2026-08-24',
            'initial_quantity' => 9,
            'available_quantity' => 9,
        ]);
        $this->assertSame('2.0000', $ingredient->fresh()->current_stock);
        $this->travelBack();
    }
}
