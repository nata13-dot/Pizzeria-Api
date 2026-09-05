<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_rules_award_once_and_points_redeem_fifo_into_order_discount(): void
    {
        $this->seed();
        $user = User::first();
        $customer = Customer::create(['branch_id' => $user->branch_id, 'name' => 'Ana', 'phone' => '555']);
        LoyaltyRule::create(['branch_id' => $user->branch_id, 'name' => 'Por cien', 'type' => 'per_amount', 'threshold' => 100, 'points' => 10, 'expires_days' => 30]);
        LoyaltyRule::create(['branch_id' => $user->branch_id, 'name' => 'Por pedido', 'type' => 'per_order', 'threshold' => 1, 'points' => 3]);
        $delivered = Order::create([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'subtotal' => 250,
            'total' => 250,
        ]);
        $service = app(LoyaltyService::class);
        $service->award($delivered);
        $service->award($delivered);
        $this->assertEquals(23, $customer->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 2);

        $draft = Order::create([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => 2,
            'status' => 'draft',
            'type' => 'pickup', 'contact_name' => 'Cliente local', 'contact_phone' => '5551234567',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $redemption = $service->redeem($customer, 8, $user, $draft, 9999);

        $this->assertEquals(8, $redemption->value);
        $this->assertEquals(15, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('orders', ['id' => $draft->id, 'discount' => 8, 'total' => 92]);
        $this->assertDatabaseHas('loyalty_redemptions', ['customer_id' => $customer->id, 'points' => 8, 'value' => 8]);
    }

    public function test_expiration_also_applies_to_positive_manual_adjustments(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        $customer = Customer::create([
            'branch_id' => $user->branch_id,
            'name' => 'Cliente con ajuste',
            'phone' => '555-0102',
        ]);
        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'type' => 'adjustment',
            'points' => 12,
            'remaining_points' => 12,
            'expires_at' => now()->subMinute(),
            'comment' => 'Bonificación temporal',
        ]);

        $this->assertSame(1, app(LoyaltyService::class)->expire($customer));
        $this->assertEquals(0, $customer->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'type' => 'expired',
            'points' => -12,
        ]);
    }
}
