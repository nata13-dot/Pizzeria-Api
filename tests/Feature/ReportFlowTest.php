<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashDay;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $this->date = CarbonImmutable::now($this->admin->branch->timezone)->toDateString();
        Sanctum::actingAs($this->admin);
    }

    public function test_sales_products_categories_and_profit_separate_courtesies_and_cancelled_orders(): void
    {
        [$category, $variant, $ingredient] = $this->catalog();
        InventoryBatch::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'lot_code' => 'COST-1',
            'received_at' => $this->date,
            'initial_quantity' => 100,
            'available_quantity' => 100,
            'unit_cost' => 3,
        ]);

        $paid = $this->order([
            'daily_number' => 1,
            'subtotal' => 120,
            'discount' => 20,
            'total' => 100,
            'scheduled_at' => CarbonImmutable::now()->addDay(),
        ]);
        $paidItem = $paid->items()->create([
            'product_variant_id' => $variant->id,
            'name' => 'Pizza grande',
            'quantity' => 2,
            'unit_price' => 60,
            'total' => 120,
        ]);
        $paidItem->ingredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 2]);
        $paid->payments()->createMany([
            ['method' => 'cash', 'amount' => 40, 'user_id' => $this->admin->id],
            ['method' => 'transfer', 'amount' => 60, 'user_id' => $this->admin->id],
        ]);

        $courtesy = $this->order([
            'daily_number' => 2,
            'subtotal' => 80,
            'total' => 80,
            'courtesy' => true,
        ]);
        $courtesyItem = $courtesy->items()->create([
            'product_variant_id' => $variant->id,
            'name' => 'Pizza grande',
            'quantity' => 1,
            'unit_price' => 80,
            'total' => 80,
        ]);
        $courtesyItem->ingredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1]);

        $cancelled = $this->order([
            'daily_number' => 3,
            'status' => 'cancelled',
            'subtotal' => 70,
            'discount' => 5,
            'total' => 65,
        ]);
        $cancelled->items()->create([
            'product_variant_id' => $variant->id,
            'name' => 'Pizza grande',
            'quantity' => 10,
            'unit_price' => 7,
            'total' => 70,
        ]);
        $cancelled->histories()->create([
            'user_id' => $this->admin->id,
            'from_status' => 'confirmed',
            'to_status' => 'cancelled',
            'comment' => 'Pedido duplicado',
        ]);
        $this->otherBranchOrder(999);

        $sales = $this->getJson("/api/reports/sales?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonPath('summary.orders', 2)
            ->assertJsonPath('summary.sales', 100)
            ->assertJsonPath('summary.discounts', 20)
            ->assertJsonPath('summary.courtesy_orders', 1)
            ->assertJsonPath('summary.courtesy_total', 80)
            ->assertJsonPath('summary.cancelled_orders', 1)
            ->assertJsonPath('summary.cancelled_total', 65)
            ->assertJsonPath('summary.scheduled_orders', 1)
            ->assertJsonPath('cancelled.0.reason', 'Pedido duplicado');
        $this->assertCount(1, $sales->json('scheduled'));
        $this->getJson("/api/reports/sales?from={$this->date}&to={$this->date}&payment_method=mixed")
            ->assertOk()
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonPath('summary.sales', 100);
        $this->getJson("/api/reports/sales?from={$this->date}&to={$this->date}&payment_method=courtesy")
            ->assertOk()
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonPath('summary.sales', 0)
            ->assertJsonPath('summary.courtesy_total', 80);

        $this->getJson("/api/reports/products?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.quantity', 3)
            ->assertJsonPath('0.paid_quantity', 2)
            ->assertJsonPath('0.courtesy_quantity', 1)
            ->assertJsonPath('0.gross_sales', 120)
            ->assertJsonPath('0.discounts', 20)
            ->assertJsonPath('0.sales', 100)
            ->assertJsonPath('0.courtesy_total', 80);
        $this->getJson("/api/reports/products?from={$this->date}&to={$this->date}&group_by=category")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.category_id', $category->id)
            ->assertJsonPath('0.name', 'Pizzas')
            ->assertJsonPath('0.quantity', 3);
        $this->getJson("/api/reports/profit?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.sales', 100)
            ->assertJsonPath('0.estimated_cost', 9)
            ->assertJsonPath('0.courtesy_estimated_cost', 3)
            ->assertJsonPath('0.estimated_profit', 91)
            ->assertJsonPath('0.missing_cost_ingredient_ids', []);
    }

    public function test_inventory_and_purchase_reports_include_consumption_expiry_waste_and_summaries(): void
    {
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create([
            'branch_id' => $this->admin->branch_id,
            'base_unit_id' => $unit->id,
            'name' => 'Queso reporte',
            'minimum_stock' => 20,
            'critical_stock' => 5,
            'expiry_alert_days' => 3,
        ]);
        $usable = InventoryBatch::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'lot_code' => 'PRONTO',
            'received_at' => $this->date,
            'expires_at' => CarbonImmutable::parse($this->date)->addDay(),
            'initial_quantity' => 20,
            'available_quantity' => 10,
            'unit_cost' => 2,
        ]);
        $expired = InventoryBatch::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'lot_code' => 'VENCIDO',
            'received_at' => CarbonImmutable::parse($this->date)->subDays(10),
            'expires_at' => CarbonImmutable::parse($this->date)->subDay(),
            'initial_quantity' => 4,
            'available_quantity' => 4,
            'unit_cost' => 1,
        ]);
        foreach ([['sale', -3], ['production_input', -2]] as [$type, $quantity]) {
            InventoryMovement::create([
                'branch_id' => $this->admin->branch_id,
                'ingredient_id' => $ingredient->id,
                'inventory_batch_id' => $usable->id,
                'user_id' => $this->admin->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => 15,
                'quantity_after' => 15 + $quantity,
            ]);
        }
        InventoryMovement::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'inventory_batch_id' => $usable->id,
            'user_id' => $this->admin->id,
            'type' => 'return',
            'quantity' => 1,
            'quantity_before' => 10,
            'quantity_after' => 11,
        ]);
        InventoryAdjustment::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'inventory_batch_id' => $usable->id,
            'user_id' => $this->admin->id,
            'quantity' => -2,
            'reason' => 'waste',
        ]);
        InventoryAdjustment::create([
            'branch_id' => $this->admin->branch_id,
            'ingredient_id' => $ingredient->id,
            'inventory_batch_id' => $expired->id,
            'user_id' => $this->admin->id,
            'quantity' => -1,
            'reason' => 'expiry',
        ]);

        $supplier = Supplier::create(['branch_id' => $this->admin->branch_id, 'name' => 'Proveedor reporte']);
        $purchase = Purchase::create([
            'branch_id' => $this->admin->branch_id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'purchased_at' => $this->date,
            'payment_source' => 'cash',
            'total' => 50,
        ]);
        $purchase->items()->create([
            'ingredient_id' => $ingredient->id,
            'presentations_quantity' => 1,
            'base_quantity' => 20,
            'total_cost' => 50,
            'base_unit_cost' => 2.5,
        ]);
        $otherUser = $this->otherBranchUser();
        Purchase::create([
            'branch_id' => $otherUser->branch_id,
            'user_id' => $otherUser->id,
            'purchased_at' => $this->date,
            'payment_source' => 'cash',
            'total' => 999,
        ]);

        $this->getJson("/api/reports/inventory?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonPath('summary.ingredients', 1)
            ->assertJsonPath('summary.low_stock', 1)
            ->assertJsonPath('summary.critical_stock', 0)
            ->assertJsonPath('summary.expiring_batches', 1)
            ->assertJsonPath('summary.expired_batches', 1)
            ->assertJsonPath('summary.consumed_quantity', 4)
            ->assertJsonPath('summary.waste_quantity', 3)
            ->assertJsonPath('ingredients.0.current_stock', '10.0000')
            ->assertJsonPath('consumption.0.sale_quantity', 2)
            ->assertJsonPath('consumption.0.production_quantity', 2)
            ->assertJsonPath('consumption.0.returned_quantity', 1)
            ->assertJsonPath('consumption.0.estimated_cost', 8)
            ->assertJsonPath('waste', 3);

        $this->getJson("/api/reports/purchases?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $purchase->id)
            ->assertJsonCount(1, '0.items');
        $this->getJson("/api/reports/purchases?from={$this->date}&to={$this->date}&include_summary=1")
            ->assertOk()
            ->assertJsonPath('summary.purchases', 1)
            ->assertJsonPath('summary.items', 1)
            ->assertJsonPath('summary.total', 50)
            ->assertJsonPath('summary.cash_impact', 50)
            ->assertJsonPath('by_payment_source.0.payment_source', 'cash');
    }

    public function test_customer_points_and_operational_time_reports_are_scoped_and_use_completed_samples(): void
    {
        $customer = Customer::create([
            'branch_id' => $this->admin->branch_id,
            'name' => 'Cliente frecuente',
            'phone' => '555-1000',
        ]);
        $this->order(['daily_number' => 1, 'customer_id' => $customer->id, 'subtotal' => 100, 'total' => 100]);
        $this->order(['daily_number' => 2, 'customer_id' => $customer->id, 'subtotal' => 80, 'total' => 80, 'courtesy' => true]);
        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'earned',
            'points' => 10,
            'remaining_points' => 6,
        ]);
        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'redeemed',
            'points' => -4,
            'remaining_points' => 0,
        ]);
        $reversedEarned = LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'earned',
            'points' => 2,
            'remaining_points' => 0,
        ]);
        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'adjustment',
            'points' => -2,
            'remaining_points' => 0,
            'reversal_of_transaction_id' => $reversedEarned->id,
        ]);
        LoyaltyRedemption::create([
            'customer_id' => $customer->id,
            'user_id' => $this->admin->id,
            'points' => 4,
            'value' => 4,
        ]);
        LoyaltyRedemption::create([
            'customer_id' => $customer->id,
            'user_id' => $this->admin->id,
            'points' => 3,
            'value' => 3,
            'cancelled_at' => now(),
            'cancelled_by' => $this->admin->id,
            'cancellation_comment' => 'Orden cancelada',
        ]);
        $otherUser = $this->otherBranchUser();
        $otherCustomer = Customer::create(['branch_id' => $otherUser->branch_id, 'name' => 'Cliente ajeno', 'phone' => '555-9999']);
        LoyaltyTransaction::create(['customer_id' => $otherCustomer->id, 'type' => 'earned', 'points' => 999, 'remaining_points' => 999]);

        $this->getJson("/api/reports/customers?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $customer->id)
            ->assertJsonPath('0.orders_count', 2)
            ->assertJsonPath('0.completed_orders_count', 2)
            ->assertJsonPath('0.orders_total', 100)
            ->assertJsonPath('0.courtesy_orders_count', 1)
            ->assertJsonPath('0.courtesy_total', 80)
            ->assertJsonPath('0.points_generated', 10)
            ->assertJsonPath('0.reversed_points_generated', 2)
            ->assertJsonPath('0.points_redeemed', 4)
            ->assertJsonPath('0.redemption_value', 4)
            ->assertJsonPath('0.reversed_points_redemptions', 3)
            ->assertJsonPath('0.points_balance', 6);

        $delivery = $this->order(['daily_number' => 3, 'type' => 'delivery']);
        $prepared = $this->order(['daily_number' => 4, 'status' => 'prepared']);
        $this->history($delivery, [
            'confirmed' => '09:55:00',
            'kitchen_pending' => '10:00:00',
            'preparing' => '10:05:00',
            'prepared' => '10:20:00',
            'on_way' => '10:30:00',
            'delivered' => '11:00:00',
        ]);
        $this->history($prepared, [
            'kitchen_pending' => '12:00:00',
            'preparing' => '12:02:00',
            'prepared' => '12:10:00',
        ]);
        $foreign = $this->otherBranchOrder(5);
        $this->history($foreign, [
            'kitchen_pending' => '08:00:00',
            'prepared' => '10:00:00',
            'on_way' => '10:00:00',
            'delivered' => '12:00:00',
        ]);

        $this->getJson("/api/reports/times?from={$this->date}&to={$this->date}")
            ->assertOk()
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('kitchen_samples', 2)
            ->assertJsonPath('delivery_samples', 1)
            ->assertJsonPath('average_queue_minutes', 3.5)
            ->assertJsonPath('average_preparation_minutes', 11.5)
            ->assertJsonPath('average_kitchen_minutes', 15)
            ->assertJsonPath('average_delivery_minutes', 30)
            ->assertJsonPath('average_fulfillment_minutes', 65);
    }

    public function test_cash_report_classifies_mixed_sales_and_excludes_cancelled_and_other_branches(): void
    {
        $cash = $this->order(['daily_number' => 1, 'subtotal' => 100, 'total' => 100]);
        $this->payment($cash, 'cash', 100);
        $mixed = $this->order(['daily_number' => 2, 'subtotal' => 100, 'total' => 100, 'scheduled_at' => CarbonImmutable::now()->addHour()]);
        $this->payment($mixed, 'cash', 40);
        $this->payment($mixed, 'transfer', 60);
        $transfer = $this->order(['daily_number' => 3, 'subtotal' => 80, 'total' => 80]);
        $this->payment($transfer, 'transfer', 80);
        $this->order(['daily_number' => 4, 'subtotal' => 50, 'total' => 50, 'courtesy' => true]);
        $cancelled = $this->order(['daily_number' => 5, 'status' => 'cancelled', 'subtotal' => 70, 'total' => 70]);
        $this->payment($cancelled, 'cash', 70);
        $foreign = $this->otherBranchOrder(999);
        $this->payment($foreign, 'cash', 999);

        $day = CashDay::create([
            'branch_id' => $this->admin->branch_id,
            'date' => $this->date,
            'opened_by' => $this->admin->id,
            'opening_amount' => 10,
        ]);
        $day->movements()->createMany([
            ['user_id' => $this->admin->id, 'type' => 'income', 'amount' => 5, 'category' => 'extra'],
            ['user_id' => $this->admin->id, 'type' => 'expense', 'amount' => 3, 'category' => 'operación'],
        ]);
        Purchase::create([
            'branch_id' => $this->admin->branch_id,
            'user_id' => $this->admin->id,
            'purchased_at' => $this->date,
            'payment_source' => 'cash',
            'total' => 20,
        ]);
        Purchase::create([
            'branch_id' => $this->admin->branch_id,
            'user_id' => $this->admin->id,
            'purchased_at' => $this->date,
            'payment_source' => 'owner',
            'total' => 30,
        ]);

        $summary = app(CashReportService::class)->summary($this->admin->branch_id, $this->date);
        $this->assertSame(4, $summary['orders']);
        $this->assertSame(280.0, $summary['gross_sales']);
        $this->assertSame(140.0, $summary['cash']);
        $this->assertSame(140.0, $summary['transfer']);
        $this->assertSame(1, $summary['cash_only_orders']);
        $this->assertSame(100.0, $summary['cash_only_sales']);
        $this->assertSame(1, $summary['transfer_only_orders']);
        $this->assertSame(80.0, $summary['transfer_only_sales']);
        $this->assertSame(1, $summary['mixed_orders']);
        $this->assertSame(100.0, $summary['mixed_sales']);
        $this->assertSame(1, $summary['courtesy_orders']);
        $this->assertSame(50.0, $summary['courtesy']);
        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(1, $summary['scheduled']);
        $this->assertSame(20.0, $summary['cash_purchases']);
        $this->assertSame(132.0, $summary['expected_cash']);
    }

    private function catalog(): array
    {
        $category = ProductCategory::create([
            'branch_id' => $this->admin->branch_id,
            'name' => 'Pizzas',
        ]);
        $product = Product::create([
            'branch_id' => $this->admin->branch_id,
            'product_category_id' => $category->id,
            'name' => 'Pizza reporte',
            'type' => 'pizza',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Grande',
            'price' => 60,
        ]);
        $ingredient = Ingredient::create([
            'branch_id' => $this->admin->branch_id,
            'base_unit_id' => Unit::where('symbol', 'pz')->value('id'),
            'name' => 'Masa costo reporte',
        ]);

        return [$category, $variant, $ingredient];
    }

    private function order(array $overrides = [], ?User $user = null): Order
    {
        $user ??= $this->admin;

        return Order::create(array_merge([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'order_date' => $this->date,
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'courtesy' => false,
        ], $overrides));
    }

    private function otherBranchUser(): User
    {
        $branch = Branch::firstOrCreate(['code' => 'REPORT-OTHER'], [
            'name' => 'Sucursal ajena',
            'timezone' => 'America/Mexico_City',
        ]);

        return User::firstOrCreate(['email' => 'other-report@test.local'], [
            'name' => 'Admin ajeno',
            'password' => 'password',
            'role_id' => Role::where('slug', 'administrador')->value('id'),
            'branch_id' => $branch->id,
        ]);
    }

    private function otherBranchOrder(float $total): Order
    {
        $user = $this->otherBranchUser();

        return $this->order([
            'daily_number' => 1,
            'subtotal' => $total,
            'total' => $total,
            'type' => 'delivery',
        ], $user);
    }

    private function history(Order $order, array $statuses): void
    {
        $timezone = $this->admin->branch->timezone;
        foreach ($statuses as $status => $time) {
            $createdAt = CarbonImmutable::parse("{$this->date} {$time}", $timezone)->utc();
            DB::table('order_status_histories')->insert([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'to_status' => $status,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function payment(Order $order, string $method, float $amount): void
    {
        $payment = $order->payments()->create([
            'method' => $method,
            'amount' => $amount,
            'user_id' => $order->user_id,
        ]);
        $paidAt = CarbonImmutable::parse("{$this->date} 23:00:00", $this->admin->branch->timezone)->utc();
        $payment->timestamps = false;
        $payment->created_at = $paidAt;
        $payment->updated_at = $paidAt;
        $payment->save();
    }
}
