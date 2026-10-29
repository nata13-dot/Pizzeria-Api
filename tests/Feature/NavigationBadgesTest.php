<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NavigationBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_badges_count_pending_work_only_for_modules_the_user_can_open(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $order = fn (int $number, string $status, string $type) => Order::create(['branch_id' => $admin->branch_id, 'user_id' => $admin->id, 'order_date' => today(), 'daily_number' => $number, 'status' => $status, 'type' => $type, 'contact_name' => 'Cliente', 'contact_phone' => '5551234567', 'subtotal' => 100, 'total' => 100]);
        $order(1, 'kitchen_pending', 'pickup');
        $order(2, 'kitchen_pending', 'delivery');
        $order(3, 'ready', 'delivery');
        $order(4, 'ready', 'pickup');
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Sin existencia', 'minimum_stock' => 100, 'critical_stock' => 10]);
        $expectedInventory = app(\App\Services\InventoryService::class)->stockQuery($admin->branch_id)->where('ingredients.active', true)->get()->whereIn('stock_status', ['critical', 'out'])->count();

        Sanctum::actingAs($admin);
        $this->getJson('/api/navigation/badges')->assertOk()->assertExactJson(['kitchen' => 2, 'delivery' => 1, 'inventory' => $expectedInventory]);
        $this->assertGreaterThanOrEqual(1, $expectedInventory);

        Sanctum::actingAs(User::where('email', 'repartidor@pizzeria.local')->firstOrFail());
        $this->getJson('/api/navigation/badges')->assertOk()->assertJsonPath('delivery', 1)->assertJsonMissingPath('inventory');

        Sanctum::actingAs(User::where('email', 'cocina@pizzeria.local')->firstOrFail());
        $this->getJson('/api/navigation/badges')->assertOk()->assertJsonPath('kitchen', 2)->assertJsonMissingPath('delivery')->assertJsonMissingPath('inventory');
    }
}
