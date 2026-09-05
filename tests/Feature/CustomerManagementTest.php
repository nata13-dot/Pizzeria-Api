<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_history_addresses_and_manual_point_adjustments_are_complete(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $customer = $this->postJson('/api/customers', [
            'name' => 'María López',
            'phone' => '555-0102',
            'birth_date' => '1990-08-24',
            'notes' => 'Cliente frecuente',
        ])->assertCreated()->json();

        $home = $this->postJson("/api/customers/{$customer['id']}/addresses", [
            'label' => 'Casa',
            'address' => 'Calle Uno 10',
            'references' => 'Portón rojo',
            'delivery_zone' => 'Centro',
            'notes' => 'Tocar el timbre',
            'is_default' => true,
        ])->assertCreated()->assertJsonPath('delivery_zone', 'Centro')->json();

        $work = $this->postJson("/api/customers/{$customer['id']}/addresses", [
            'label' => 'Trabajo',
            'address' => 'Avenida Dos 20',
            'is_default' => true,
        ])->assertCreated()->json();
        $this->assertDatabaseHas('customer_addresses', ['id' => $home['id'], 'is_default' => false]);

        $order = Order::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $admin->id,
            'customer_id' => $customer['id'],
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $this->getJson("/api/customers/{$customer['id']}/orders")
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);

        $this->postJson("/api/customers/{$customer['id']}/adjust-points", [
            'points' => 10,
            'comment' => 'Bono inicial',
        ])->assertCreated()->assertJsonPath('remaining_points', 10);
        $this->postJson("/api/customers/{$customer['id']}/adjust-points", [
            'points' => -3,
            'comment' => 'Corrección autorizada',
        ])->assertCreated();

        $this->getJson("/api/customers/{$customer['id']}/loyalty")
            ->assertOk()
            ->assertJsonPath('points_balance', 7)
            ->assertJsonCount(2, 'transactions.data');

        $this->deleteJson("/api/customers/{$customer['id']}/addresses/{$work['id']}")->assertNoContent();
        $this->assertDatabaseHas('customer_addresses', ['id' => $home['id'], 'is_default' => true]);
    }

    public function test_birthday_and_scheduled_promotion_rules_can_be_managed_and_awarded_once(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $customer = Customer::create([
            'branch_id' => $admin->branch_id,
            'name' => 'Cumpleañera',
            'phone' => '555-0202',
            'birth_date' => today()->subYears(25),
        ]);

        $birthday = $this->postJson('/api/loyalty-rules', [
            'name' => 'Cumpleaños',
            'type' => 'birthday',
            'points' => 5,
        ])->assertCreated()->json();
        $promotion = $this->postJson('/api/loyalty-rules', [
            'name' => 'Promoción vigente',
            'type' => 'promotion',
            'points' => 2,
            'conditions' => [
                'starts_at' => today()->subDay()->toDateString(),
                'ends_at' => today()->addDay()->toDateString(),
            ],
        ])->assertCreated()->json();

        $order = Order::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'subtotal' => 100,
            'total' => 100,
        ]);
        app(LoyaltyService::class)->award($order);
        app(LoyaltyService::class)->award($order);
        $this->assertSame(7.0, $customer->fresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 2);

        $this->putJson("/api/loyalty-rules/{$birthday['id']}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('active', false);
        $this->deleteJson("/api/loyalty-rules/{$promotion['id']}")->assertNoContent();
        $this->assertFalse(LoyaltyRule::findOrFail($promotion['id'])->active);
    }

    public function test_disabled_loyalty_program_does_not_award_points(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Sin puntos', 'phone' => '555-0303']);
        LoyaltyRule::create(['branch_id' => $admin->branch_id, 'name' => 'Por pedido', 'type' => 'per_order', 'threshold' => 1, 'points' => 3]);
        Setting::create(['branch_id' => $admin->branch_id, 'key' => 'loyalty_enabled', 'value' => false]);
        $order = Order::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'subtotal' => 100,
            'total' => 100,
        ]);

        app(LoyaltyService::class)->award($order);

        $this->assertSame(0.0, $customer->fresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }
}
