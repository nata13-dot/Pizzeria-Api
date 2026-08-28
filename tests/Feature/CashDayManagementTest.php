<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashDay;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashDayManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_is_idempotent_and_current_cash_includes_movements_and_summary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 01:00:00 UTC'));
        $this->seed();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($cashier);

        $opened = $this->postJson('/api/cash-days/open', ['opening_amount' => 75])
            ->assertCreated()
            ->assertJsonPath('date', '2026-08-25T00:00:00.000000Z')
            ->assertJsonPath('opening_amount', '75.00');
        $dayId = $opened->json('id');

        $this->postJson('/api/cash-days/open', ['opening_amount' => 999])
            ->assertOk()
            ->assertJsonPath('id', $dayId)
            ->assertJsonPath('opening_amount', '75.00');
        $this->assertDatabaseCount('cash_days', 1);

        $this->postJson("/api/cash-days/{$dayId}/movements", [
            'type' => 'income',
            'amount' => 20,
            'category' => 'Cambio adicional',
            'description' => 'Fondo agregado durante el turno',
        ])->assertCreated();

        $this->getJson('/api/cash-days/current')
            ->assertOk()
            ->assertJsonPath('id', $dayId)
            ->assertJsonPath('date', '2026-08-25')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('movements_count', 1)
            ->assertJsonPath('movements.0.amount', 20)
            ->assertJsonPath('movements.0.user.id', $cashier->id)
            ->assertJsonPath('summary.other_income', 20)
            ->assertJsonPath('summary.expected_cash', 95);

        $this->travelBack();
    }

    public function test_current_cash_returns_an_operable_not_opened_state_without_guessing_an_id(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/cash-days/current')
            ->assertOk()
            ->assertJsonPath('id', null)
            ->assertJsonPath('status', 'not_opened')
            ->assertJsonPath('movements', [])
            ->assertJsonPath('summary.cash_day_id', null);
    }

    public function test_closed_cash_day_rejects_every_later_mutation(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($cashier);
        $dayId = $this->postJson('/api/cash-days/open', ['opening_amount' => 100])
            ->assertCreated()
            ->json('id');

        $this->postJson("/api/cash-days/{$dayId}/close", ['actual_amount' => 100])
            ->assertOk()
            ->assertJsonPath('expected_amount', '100.00')
            ->assertJsonPath('difference', '0.00');
        $this->postJson("/api/cash-days/{$dayId}/movements", [
            'type' => 'expense',
            'amount' => 1,
            'category' => 'Intento tardío',
        ])->assertUnprocessable();
        $this->postJson("/api/cash-days/{$dayId}/close", ['actual_amount' => 100])
            ->assertUnprocessable();

        $this->getJson('/api/cash-days/current')
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('is_closed', true);
    }

    public function test_listing_and_details_are_scoped_to_the_authenticated_branch(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $localDay = CashDay::create([
            'branch_id' => $admin->branch_id,
            'date' => '2026-08-20',
            'opened_by' => $admin->id,
            'opening_amount' => 50,
        ]);
        CashDay::create([
            'branch_id' => $admin->branch_id,
            'date' => '2026-08-19',
            'opened_by' => $admin->id,
            'opening_amount' => 40,
            'closed_by' => $admin->id,
            'closed_at' => now(),
            'expected_amount' => 40,
            'actual_amount' => 40,
            'difference' => 0,
        ]);

        $otherBranch = Branch::create(['name' => 'Otra sucursal', 'code' => 'OTRA']);
        $otherUser = User::create([
            'name' => 'Caja externa',
            'email' => 'caja-externa@test.local',
            'password' => 'password123',
            'role_id' => Role::where('slug', 'cajero')->value('id'),
            'branch_id' => $otherBranch->id,
            'active' => true,
        ]);
        $otherDay = CashDay::create([
            'branch_id' => $otherBranch->id,
            'date' => '2026-08-20',
            'opened_by' => $otherUser->id,
            'opening_amount' => 999,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/cash-days?date=2026-08-20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $localDay->id)
            ->assertJsonMissing(['id' => $otherDay->id]);
        $this->getJson('/api/cash-days?status=closed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'closed');
        $this->getJson("/api/cash-days/{$localDay->id}")
            ->assertOk()
            ->assertJsonPath('id', $localDay->id);
        $this->getJson("/api/cash-days/{$otherDay->id}")->assertNotFound();
    }

    public function test_opening_uses_each_branch_local_date_and_rejects_future_business_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 01:00:00 UTC'));
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $admin->branch()->update(['timezone' => 'America/Mexico_City']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/cash-days/open', ['opening_amount' => 0])
            ->assertCreated()
            ->assertJsonPath('date', '2026-08-25T00:00:00.000000Z');
        $this->getJson('/api/cash-days/current')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-25');
        $this->postJson('/api/cash-days/open', [
            'date' => '2026-08-26',
            'opening_amount' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->travelBack();
    }

    public function test_cash_purchases_require_an_open_day_and_are_not_duplicated_as_movements(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $unit = Unit::where('symbol', 'pz')->firstOrFail();
        $ingredient = Ingredient::create([
            'branch_id' => $admin->branch_id,
            'base_unit_id' => $unit->id,
            'name' => 'Insumo pagado desde caja',
        ]);
        $presentation = IngredientPresentation::create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Pieza',
            'quantity' => 1,
            'equivalent_unit_id' => $unit->id,
            'base_quantity' => 1,
            'active' => true,
        ]);
        $payload = [
            'purchased_at' => today()->toDateString(),
            'payment_source' => 'cash',
            'items' => [[
                'ingredient_presentation_id' => $presentation->id,
                'presentations_quantity' => 1,
                'total_cost' => 25,
            ]],
        ];

        $this->postJson('/api/purchases', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_source');
        $dayId = $this->postJson('/api/cash-days/open', ['opening_amount' => 100])
            ->assertCreated()
            ->json('id');
        $this->postJson('/api/purchases', $payload)->assertCreated();
        $this->assertDatabaseCount('cash_movements', 0);
        $this->getJson('/api/cash-days/current')
            ->assertOk()
            ->assertJsonPath('summary.cash_purchases', 25)
            ->assertJsonPath('summary.expected_cash', 75);

        $this->postJson("/api/cash-days/{$dayId}/close", ['actual_amount' => 75])->assertOk();
        $this->postJson('/api/purchases', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_source');
        $this->assertDatabaseCount('purchases', 1);
    }
}
