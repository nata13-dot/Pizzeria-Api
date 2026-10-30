<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_today_and_only_returns_sections_the_user_may_open(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $clock = app(BranchClock::class);
        $now = $clock->now($admin->branch_id);
        $today = $clock->today($admin->branch_id);
        $number = 0;
        $order = function (string $status, string $type, float $total, $date, $createdAt, array $items = []) use ($admin, &$number) {
            $order = Order::create(['branch_id' => $admin->branch_id, 'user_id' => $admin->id, 'order_date' => $date, 'daily_number' => ++$number, 'status' => $status, 'type' => $type, 'contact_name' => 'Cliente '.$number, 'contact_phone' => '5551234567', 'subtotal' => $total, 'total' => $total]);
            $order->forceFill(['created_at' => $createdAt->utc()])->save();
            foreach ($items as [$name, $quantity, $lineTotal]) {
                $order->items()->create(['name' => $name, 'quantity' => $quantity, 'unit_price' => $lineTotal / $quantity, 'total' => $lineTotal]);
            }
            $order->payments()->create(['method' => 'cash', 'amount' => $total, 'user_id' => $admin->id]);

            return $order;
        };
        $earlier = $now->subMinutes(30);
        $order('delivered', 'pickup', 200, $today, $earlier, [['Pizza Grande', 1, 200]]);
        $order('kitchen_pending', 'delivery', 300, $today, $earlier, [['Pizza Grande', 2, 300]]);
        $order('ready', 'pickup', 100, $today, $earlier, [['Refresco', 4, 100]]);
        $order('cancelled', 'pickup', 999, $today, $earlier, [['Pizza Grande', 9, 999]]);
        // Last week: one sale before this time of day counts, one after it does not.
        $order('delivered', 'pickup', 150, $today->subWeek(), $now->subWeek()->subHour());
        $order('delivered', 'pickup', 500, $today->subWeek(), $now->subWeek()->addMinute());
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Aaa sin existencia', 'minimum_stock' => 100, 'critical_stock' => 10]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('sales.total', 600)->assertJsonPath('sales.orders', 3)->assertJsonPath('sales.average_ticket', 200)
            ->assertJsonPath('sales.last_week_same_time.sales', 150)->assertJsonPath('sales.last_week_same_time.orders', 1)
            ->assertJsonPath('sales.cancelled', 1)
            ->assertJsonPath('order_types.delivery.orders', 1)->assertJsonPath('order_types.pickup.sales', 300)
            ->assertJsonPath('top_products.0.name', 'Refresco')->assertJsonPath('top_products.0.quantity', 4)
            ->assertJsonPath('top_products.1.name', 'Pizza Grande')->assertJsonPath('top_products.1.quantity', 3)
            ->assertJsonPath('pipeline.kitchen_pending', 1)->assertJsonPath('pipeline.ready', 1)->assertJsonPath('pipeline.on_way', 0)
            ->assertJsonPath('inventory.shortages.0.name', 'Aaa sin existencia')->assertJsonPath('inventory.shortages.0.status', 'out')
            ->assertJsonCount(4, 'recent_orders');
        $this->assertSame(600.0, (float) collect($response->json('sales.by_hour'))->sum('sales'));

        Sanctum::actingAs(User::where('email', 'cocina@pizzeria.local')->firstOrFail());
        $this->getJson('/api/dashboard')->assertOk()->assertJsonMissingPath('sales')->assertJsonMissingPath('inventory')->assertJsonPath('pipeline.kitchen_pending', 1);
    }
}
