<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoyaltyCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_draft_restores_redeemed_points_once(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente canje', 'phone' => '555-1000']);
        LoyaltyTransaction::create(['customer_id' => $customer->id, 'type' => 'adjustment', 'points' => 10, 'remaining_points' => 10]);
        $order = $this->order($admin, $customer, 'draft');
        app(LoyaltyService::class)->redeem($customer, 5, $admin, $order);
        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/cancel", ['comment' => 'Cliente desistió'])->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel", ['comment' => 'Reintento'])->assertOk();

        $this->assertSame(10.0, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_redemptions', ['order_id' => $order->id, 'cancelled_by' => $admin->id]);
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'adjustment')->count());
    }

    public function test_admin_can_cancel_delivered_order_and_awarded_points_are_reversed(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente entrega', 'phone' => '555-2000']);
        LoyaltyRule::create(['branch_id' => $admin->branch_id, 'name' => 'Por pedido', 'type' => 'per_order', 'threshold' => 1, 'points' => 3]);
        $order = $this->order($admin, $customer, 'delivered');
        app(LoyaltyService::class)->award($order);
        $this->assertSame(3.0, $customer->fresh()->points_balance);
        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->postJson("/api/orders/{$order->id}/cancel", ['comment' => 'Reembolso autorizado'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(0.0, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'order_id' => $order->id,
            'type' => 'adjustment',
            'points' => -3,
        ]);
    }

    private function order(User $user, Customer $customer, string $status): Order
    {
        return Order::create([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => Order::where('branch_id', $user->branch_id)->count() + 1,
            'status' => $status,
            'type' => 'pickup',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }
}
