<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use App\Services\BranchClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseAssistTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $kg = Unit::where('symbol', 'kg')->firstOrFail();
        $ingredient = $this->postJson('/api/ingredients', ['name' => 'Queso', 'base_unit_id' => $grams->id, 'minimum_stock' => 5000, 'critical_stock' => 1000])->assertCreated()->json();
        $small = $this->postJson("/api/ingredients/{$ingredient['id']}/presentations", ['name' => 'Bolsa 1 kg', 'quantity' => 1, 'equivalent_unit_id' => $kg->id])->assertCreated()->json();
        $large = $this->postJson("/api/ingredients/{$ingredient['id']}/presentations", ['name' => 'Bolsa 2 kg', 'quantity' => 2, 'equivalent_unit_id' => $kg->id])->assertCreated()->json();

        return [$user, $ingredient, $small, $large, app(BranchClock::class)->today($user->branch_id)->toDateString()];
    }

    public function test_retrying_a_purchase_with_the_same_key_never_adds_stock_twice(): void
    {
        [, $ingredient, $small, , $today] = $this->fixtures();
        $payload = ['purchased_at' => $today, 'payment_source' => 'owner', 'items' => [['ingredient_presentation_id' => $small['id'], 'presentations_quantity' => 3, 'total_cost' => 270]]];

        $first = $this->withHeader('Idempotency-Key', 'purchase-test-0001')->postJson('/api/purchases', $payload)->assertCreated()->json('id');
        $this->withHeader('Idempotency-Key', 'purchase-test-0001')->postJson('/api/purchases', $payload)->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('id', $first);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseHas('inventory_batches', ['ingredient_id' => $ingredient['id'], 'available_quantity' => 3000]);
        $this->assertDatabaseCount('inventory_batches', 1);

        $this->withHeader('Idempotency-Key', 'bad key!')->postJson('/api/purchases', $payload)->assertUnprocessable();
        $this->getJson("/api/purchases/{$first}")->assertOk()->assertJsonPath('items.0.ingredient.base_unit.symbol', 'g')->assertJsonPath('items.0.presentation.name', 'Bolsa 1 kg');
    }

    public function test_suggestions_list_shortages_with_last_used_presentation_and_price(): void
    {
        [, $ingredient, $small, $large, $today] = $this->fixtures();
        $first = $this->getJson('/api/purchases/suggestions')->assertOk()->json('data.0');
        $this->assertSame($ingredient['id'], $first['ingredient']['id']);
        $this->assertSame($small['id'], $first['suggested_presentation_id']);
        $this->assertSame(5, $first['suggested_quantity']);
        $this->assertNotEmpty($first['presentations'][0]['purchase_revision']);

        $this->postJson('/api/purchases', ['purchased_at' => $today, 'payment_source' => 'owner', 'items' => [['ingredient_presentation_id' => $large['id'], 'presentations_quantity' => 1, 'total_cost' => 150]]])->assertCreated();
        $after = $this->getJson('/api/purchases/suggestions')->assertOk()->json('data.0');
        $this->assertSame($large['id'], $after['suggested_presentation_id']);
        $this->assertSame(2, $after['suggested_quantity']);
        $this->assertEquals(150, collect($after['presentations'])->firstWhere('id', $large['id'])['last_unit_cost']);
        $this->getJson("/api/ingredients/{$ingredient['id']}")->assertOk()->assertJsonPath('presentations.1.last_unit_cost', 150);

        $this->postJson('/api/purchases', ['purchased_at' => $today, 'payment_source' => 'owner', 'items' => [['ingredient_presentation_id' => $large['id'], 'presentations_quantity' => 2, 'total_cost' => 300]]])->assertCreated();
        $this->getJson('/api/purchases/suggestions')->assertOk()->assertJsonCount(0, 'data');
    }
}
