<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Ingredient $ingredient;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::first();
        Sanctum::actingAs($this->user);
        $g = Unit::where('symbol', 'g')->first();
        $this->ingredient = Ingredient::create(['branch_id' => $this->user->branch_id, 'base_unit_id' => $g->id, 'name' => 'Queso']);
        $p = Product::create(['branch_id' => $this->user->branch_id, 'name' => 'Pizza', 'type' => 'pizza']);
        $this->variant = ProductVariant::create(['product_id' => $p->id, 'name' => 'Grande', 'price' => 200]);
        $r = Recipe::create(['product_variant_id' => $this->variant->id, 'name' => 'Pizza grande']);
        $r->items()->create(['ingredient_id' => $this->ingredient->id, 'quantity' => 250, 'component' => 'base']);
    }

    private function stock(float $q): void
    {
        InventoryBatch::create(['branch_id' => $this->user->branch_id, 'ingredient_id' => $this->ingredient->id, 'received_at' => today(), 'initial_quantity' => $q, 'available_quantity' => $q]);
    }

    private function order(string $status = 'confirmed'): array
    {
        return $this->postJson('/api/orders', ['status' => $status, 'type' => 'pickup', 'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]], 'payments' => $status === 'confirmed' ? [['method' => 'cash', 'amount' => 200]] : []])->assertCreated()->json();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', $role))
            ->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function deliveryOrder(bool $collectOnDelivery = false): array
    {
        return $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'delivery',
            'collect_on_delivery' => $collectOnDelivery,
            'delivery' => [
                'recipient' => 'Cliente entrega',
                'phone' => '5551234567',
                'address' => 'Calle Uno 10',
            ],
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]],
            'payments' => $collectOnDelivery ? [] : [['method' => 'cash', 'amount' => 200]],
        ])->assertCreated()->json();
    }

    public function test_inventory_is_only_deducted_when_sent_to_kitchen(): void
    {
        $this->stock(500);
        $o = $this->order('pending_payment');
        $this->assertEquals(500, $this->ingredient->batches()->sum('available_quantity'));
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $o['id'], 'ingredient_id' => $this->ingredient->id, 'quantity' => 250]);
        $this->postJson("/api/orders/{$o['id']}/confirm", ['payments' => [['method' => 'cash', 'amount' => 200]]])->assertOk();
        $this->assertDatabaseMissing('stock_reservations', ['order_id' => $o['id']]);
        $this->assertEquals(500, $this->ingredient->batches()->sum('available_quantity'));
        $this->postJson("/api/orders/{$o['id']}/send-to-kitchen")->assertOk()->assertJsonPath('status', 'kitchen_pending');
        $this->assertEquals(250, $this->ingredient->batches()->sum('available_quantity'));
    }

    public function test_shortage_requires_explicit_authorization_and_consumes_available_stock(): void
    {
        $this->stock(100);
        $o = $this->order();
        $this->postJson("/api/orders/{$o['id']}/send-to-kitchen")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'stock_shortage')
            ->assertJsonPath('stock_warnings.0.shortage', 150);
        $this->assertDatabaseHas('orders', ['id' => $o['id'], 'status' => 'confirmed']);
        $this->assertEquals(100, $this->ingredient->batches()->sum('available_quantity'));

        $cashier = $this->actingAsRole('cajero');
        $this->assertFalse($cashier->hasPermission('stock.override'));
        $this->assertDatabaseHas('permissions', ['slug' => 'stock.override']);
        $this->postJson("/api/orders/{$o['id']}/send-to-kitchen", ['allow_stock_shortage' => true])
            ->assertForbidden();
        $this->assertEquals(100, $this->ingredient->batches()->sum('available_quantity'));

        Sanctum::actingAs($this->user);
        $this->postJson("/api/orders/{$o['id']}/send-to-kitchen", ['allow_stock_shortage' => true])
            ->assertOk()
            ->assertJsonPath('status', 'kitchen_pending')
            ->assertJsonPath('stock_warnings.0.shortage', 150);
        $this->assertEquals(0, $this->ingredient->batches()->sum('available_quantity'));
        $this->assertDatabaseHas('orders', [
            'id' => $o['id'],
            'stock_shortage_authorized_by' => $this->user->id,
        ]);
    }

    public function test_cancel_before_preparation_returns_stock_but_after_start_records_waste(): void
    {
        $this->stock(1000);
        $a = $this->order();
        $this->postJson("/api/orders/{$a['id']}/send-to-kitchen");
        $this->postJson("/api/orders/{$a['id']}/cancel")->assertOk();
        $this->assertEquals(1000, $this->ingredient->batches()->sum('available_quantity'));
        $b = $this->order();
        $this->postJson("/api/orders/{$b['id']}/send-to-kitchen");
        $this->postJson("/api/orders/{$b['id']}/status", ['status' => 'preparing']);
        $this->postJson("/api/orders/{$b['id']}/cancel", ['comment' => 'Cliente canceló después de iniciar'])->assertOk();
        $this->assertEquals(750, $this->ingredient->batches()->sum('available_quantity'));
        $this->assertDatabaseHas('inventory_adjustments', ['reason' => 'preparation_error', 'quantity' => -250]);
    }

    public function test_preparation_pending_cancellation_is_idempotent(): void
    {
        $this->stock(500);
        $order = $this->order();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")
            ->assertOk()
            ->assertJsonPath('status', 'kitchen_pending');

        $this->postJson("/api/orders/{$order['id']}/cancel", ['comment' => 'Cliente desistió'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/orders/{$order['id']}/cancel", ['comment' => 'Reintento de red'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertEquals(500, $this->ingredient->batches()->sum('available_quantity'));
        $this->assertSame(1, InventoryMovement::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order['id'])
            ->where('type', 'return')
            ->count());
        $this->assertDatabaseCount('inventory_adjustments', 0);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order['id'],
            'from_status' => 'kitchen_pending',
            'to_status' => 'cancelled',
            'comment' => 'Cliente desistió',
        ]);
        $this->assertSame(1, DB::table('order_status_histories')
            ->where('order_id', $order['id'])
            ->where('to_status', 'cancelled')
            ->count());
    }

    public function test_cashier_cannot_cancel_advanced_order_and_admin_requires_a_reason(): void
    {
        $this->stock(500);
        $order = $this->order();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertOk();
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'preparing'])->assertOk();

        $cashier = $this->actingAsRole('cajero');
        $this->assertFalse($cashier->hasPermission('orders.cancel_advanced'));
        $this->assertDatabaseHas('permissions', ['slug' => 'orders.cancel_advanced']);
        $this->postJson("/api/orders/{$order['id']}/cancel", ['comment' => 'Intento desde caja'])
            ->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'preparing']);
        $this->assertDatabaseCount('inventory_adjustments', 0);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/orders/{$order['id']}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->postJson("/api/orders/{$order['id']}/cancel", ['comment' => 'Autorizada por gerencia'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('inventory_adjustments', [
            'ingredient_id' => $this->ingredient->id,
            'reason' => 'preparation_error',
            'quantity' => -250,
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order['id'],
            'from_status' => 'preparing',
            'to_status' => 'cancelled',
            'comment' => 'Autorizada por gerencia',
        ]);
    }

    public function test_daily_number_reuses_only_cancelled_tail(): void
    {
        $this->stock(1000);
        $a = $this->order();
        $b = $this->order();
        $this->assertSame(2, $b['daily_number']);
        $this->postJson("/api/orders/{$b['id']}/cancel");
        $c = $this->order();
        $this->assertSame(2, $c['daily_number']);
        $this->postJson("/api/orders/{$a['id']}/cancel");
        $d = $this->order();
        $this->assertSame(3, $d['daily_number']);
    }

    public function test_kitchen_order_can_advance_until_ready(): void
    {
        $this->stock(500);
        $order = $this->order();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertJsonPath('status', 'kitchen_pending');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'preparing'])->assertOk()->assertJsonPath('status', 'preparing');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'prepared'])->assertOk()->assertJsonPath('status', 'prepared');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'ready'])->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_pickup_is_completed_by_cashier_after_kitchen_marks_it_ready(): void
    {
        $this->stock(500);
        $order = $this->order();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertOk();

        $this->actingAsRole('cocina');
        foreach (['preparing', 'prepared', 'ready'] as $status) {
            $this->postJson("/api/orders/{$order['id']}/status", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('status', $status);
        }

        $this->actingAsRole('repartidor');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $cashier = $this->actingAsRole('cajero');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order['id'],
            'user_id' => $cashier->id,
            'from_status' => 'ready',
            'to_status' => 'delivered',
        ]);
    }

    public function test_delivery_cannot_skip_on_way_or_be_completed_by_cashier(): void
    {
        $this->stock(500);
        $order = $this->deliveryOrder();
        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertOk();

        $this->actingAsRole('cocina');
        foreach (['preparing', 'prepared', 'ready'] as $status) {
            $this->postJson("/api/orders/{$order['id']}/status", ['status' => $status])->assertOk();
        }

        $this->actingAsRole('repartidor');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'on_way'])
            ->assertOk()
            ->assertJsonPath('status', 'on_way');

        $this->actingAsRole('cajero');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $delivery = $this->actingAsRole('repartidor');
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order['id'],
            'user_id' => $delivery->id,
            'from_status' => 'on_way',
            'to_status' => 'delivered',
        ]);
    }

    public function test_pending_payment_expiration_uses_branch_setting(): void
    {
        Setting::create([
            'branch_id' => $this->user->branch_id,
            'key' => 'pending_payment_minutes',
            'value' => 25,
        ]);
        $before = now();
        $order = $this->order('pending_payment');

        $this->assertTrue(
            $before->copy()->addMinutes(25)->diffInSeconds($order['pending_expires_at']) <= 2,
        );
    }

    public function test_active_pending_reservations_reduce_available_stock_for_new_sales(): void
    {
        $this->stock(500);
        $pending = $this->order('pending_payment');
        $confirmed = $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'pickup',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ])->assertCreated()->json();

        $this->postJson("/api/orders/{$confirmed['id']}/send-to-kitchen")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'stock_shortage')
            ->assertJsonPath('stock_warnings.0.available', 250)
            ->assertJsonPath('stock_warnings.0.shortage', 250);

        $this->postJson("/api/orders/{$pending['id']}/cancel", ['comment' => 'Libera reserva'])->assertOk();
        $this->postJson("/api/orders/{$confirmed['id']}/send-to-kitchen")
            ->assertOk()
            ->assertJsonPath('status', 'kitchen_pending');
    }

    public function test_pending_order_reserves_without_blocking_and_reports_physical_shortage(): void
    {
        $this->stock(100);

        $pending = $this->order('pending_payment');

        $this->assertSame(150, (int) $pending['stock_warnings'][0]['shortage']);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $pending['id'],
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 250,
        ]);
        $this->assertEquals(100, $this->ingredient->batches()->sum('available_quantity'));
    }

    public function test_scheduled_order_waits_for_its_preparation_window(): void
    {
        $this->stock(500);
        $scheduledAt = now()->addHours(2)->startOfSecond();
        $order = $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'pickup',
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertCreated()->json();

        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(0, app(OrderService::class)->dispatchScheduled());

        $this->travelTo($scheduledAt->copy()->subMinutes(30));
        $this->assertSame(1, app(OrderService::class)->dispatchScheduled());
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'kitchen_pending']);
        $this->travelBack();
    }

    public function test_order_creation_is_idempotent_for_network_retries(): void
    {
        $payload = [
            'status' => 'confirmed',
            'type' => 'pickup',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ];
        $headers = ['Idempotency-Key' => 'checkout-retry-123'];
        $first = $this->postJson('/api/orders', $payload, $headers)->assertCreated()->json();
        $second = $this->postJson('/api/orders', $payload, $headers)->assertOk()->json();

        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_cash_on_delivery_must_be_collected_before_delivery(): void
    {
        $this->stock(500);
        $order = $this->deliveryOrder(true);

        $this->postJson("/api/orders/{$order['id']}/send-to-kitchen")->assertOk();

        $this->actingAsRole('cocina');
        foreach (['preparing', 'prepared', 'ready'] as $status) {
            $this->postJson("/api/orders/{$order['id']}/status", ['status' => $status])->assertOk();
        }

        $this->actingAsRole('repartidor');
        $this->postJson("/api/delivery/orders/{$order['id']}/payment-received", ['method' => 'cash'])
            ->assertStatus(422);
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'on_way'])->assertOk();
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $this->postJson("/api/delivery/orders/{$order['id']}/payment-received", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment_received', true);
        $this->postJson("/api/orders/{$order['id']}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
        $this->assertDatabaseHas('order_payments', ['order_id' => $order['id'], 'method' => 'cash', 'amount' => 200]);
    }
}
