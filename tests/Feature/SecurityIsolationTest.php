<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashDay;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cash_report_and_modifier_routes_enforce_operational_permissions(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $kitchen = User::where('email', 'cocina@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente local', 'phone' => '555-0100']);
        $address = $customer->addresses()->create(['label' => 'Casa', 'address' => 'Calle Uno']);
        $day = CashDay::create([
            'branch_id' => $admin->branch_id,
            'date' => today(),
            'opened_by' => $admin->id,
            'opening_amount' => 50,
        ]);
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Producto local', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 100]);
        $modifier = Modifier::create(['branch_id' => $admin->branch_id, 'name' => 'Extra', 'type' => 'add', 'price' => 10]);

        Sanctum::actingAs($kitchen);
        $this->getJson("/api/customers/{$customer->id}")->assertForbidden();
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'Intento'])->assertForbidden();
        $this->postJson("/api/customers/{$customer->id}/addresses", ['label' => 'Intento', 'address' => 'Otra'])->assertForbidden();
        $this->putJson("/api/customers/{$customer->id}/addresses/{$address->id}", ['label' => 'Intento'])->assertForbidden();
        $this->postJson("/api/cash-days/{$day->id}/movements", ['type' => 'income', 'amount' => 10, 'category' => 'Otro'])->assertForbidden();
        $this->postJson("/api/cash-days/{$day->id}/close", ['actual_amount' => 50])->assertForbidden();
        $this->getJson('/api/reports/cash-day')->assertForbidden();
        $this->postJson('/api/reports/daily')->assertForbidden();

        Sanctum::actingAs($cashier);
        $this->getJson("/api/customers/{$customer->id}")->assertOk();
        $this->putJson("/api/customers/{$customer->id}", ['name' => 'Cliente actualizado'])->assertOk();
        $this->postJson("/api/customers/{$customer->id}/addresses", ['label' => 'Trabajo', 'address' => 'Calle Dos'])->assertCreated();
        $this->putJson("/api/customers/{$customer->id}/addresses/{$address->id}", ['label' => 'Principal'])->assertOk();
        $this->postJson("/api/cash-days/{$day->id}/movements", ['type' => 'income', 'amount' => 10, 'category' => 'Otro'])->assertCreated();
        $this->getJson('/api/reports/cash-day')->assertOk();
        $this->postJson('/api/reports/daily')->assertForbidden();
        $this->postJson("/api/product-variants/{$variant->id}/modifiers", ['modifier_id' => $modifier->id])->assertForbidden();
        $this->postJson("/api/cash-days/{$day->id}/close", ['actual_amount' => 60])->assertOk();
    }

    public function test_cash_customer_and_redemption_records_are_isolated_by_branch_and_customer(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $otherBranch = Branch::create(['name' => 'Sucursal externa', 'code' => 'EXTERNA']);
        $otherUser = User::create([
            'name' => 'Usuario externo',
            'email' => 'externo-isolation@test.local',
            'password' => 'password123',
            'role_id' => Role::where('slug', 'cajero')->value('id'),
            'branch_id' => $otherBranch->id,
            'active' => true,
        ]);
        $otherDay = CashDay::create([
            'branch_id' => $otherBranch->id,
            'date' => today(),
            'opened_by' => $otherUser->id,
            'opening_amount' => 100,
        ]);
        $localCustomer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente local', 'phone' => '111']);
        $secondLocalCustomer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Otro cliente local', 'phone' => '222']);
        $otherCustomer = Customer::create(['branch_id' => $otherBranch->id, 'name' => 'Cliente externo', 'phone' => '333']);
        $otherOrder = $this->order($otherBranch->id, $otherUser, $otherCustomer, 1);
        $anotherCustomerOrder = $this->order($admin->branch_id, $admin, $secondLocalCustomer, 2);

        Sanctum::actingAs($admin);
        $this->getJson("/api/customers/{$otherCustomer->id}")->assertNotFound();
        $this->postJson("/api/cash-days/{$otherDay->id}/movements", ['type' => 'income', 'amount' => 10, 'category' => 'Otro'])->assertNotFound();
        $this->postJson("/api/cash-days/{$otherDay->id}/close", ['actual_amount' => 100])->assertNotFound();
        $this->postJson("/api/customers/{$localCustomer->id}/redeem-points", ['points' => 1, 'order_id' => $otherOrder->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
        $this->postJson("/api/customers/{$localCustomer->id}/redeem-points", ['points' => 1, 'order_id' => $anotherCustomerOrder->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
        $this->postJson("/api/customers/{$localCustomer->id}/redeem-points", ['points' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_document_types_are_limited_to_each_operational_role(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $kitchen = User::where('email', 'cocina@pizzeria.local')->firstOrFail();
        $delivery = User::where('email', 'repartidor@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente documento', 'phone' => '444']);
        $order = $this->order($admin->branch_id, $admin, $customer, 3);

        Sanctum::actingAs($kitchen);
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_html'])->assertForbidden();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'delivery'])->assertForbidden();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'kitchen'])->assertCreated();

        Sanctum::actingAs($delivery);
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_html'])->assertForbidden();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'kitchen'])->assertForbidden();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'delivery'])->assertCreated();

        Sanctum::actingAs($cashier);
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'customer_html'])->assertCreated();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'kitchen'])->assertCreated();
        $this->postJson("/api/orders/{$order->id}/generate-document", ['type' => 'delivery'])->assertCreated();
    }

    public function test_customer_balances_and_loyalty_rule_conditions_are_branch_scoped(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente puntos', 'phone' => '555']);
        LoyaltyTransaction::create(['customer_id' => $customer->id, 'type' => 'adjustment', 'points' => 12, 'remaining_points' => 12]);
        $localProduct = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Producto puntos', 'type' => 'other']);
        $localCategory = ProductCategory::create(['branch_id' => $admin->branch_id, 'name' => 'Categoría puntos']);
        $otherBranch = Branch::create(['name' => 'Sucursal reglas', 'code' => 'RULES']);
        $otherProduct = Product::create(['branch_id' => $otherBranch->id, 'name' => 'Producto externo', 'type' => 'other']);
        $otherCategory = ProductCategory::create(['branch_id' => $otherBranch->id, 'name' => 'Categoría externa']);

        Sanctum::actingAs($cashier);
        $customers = $this->getJson('/api/customers')->assertOk()->json('data');
        $this->assertSame(12.0, (float) collect($customers)->firstWhere('id', $customer->id)['points_balance']);

        Sanctum::actingAs($admin);
        $base = ['name' => 'Regla', 'threshold' => 1, 'points' => 2];
        $this->postJson('/api/loyalty-rules', $base + ['type' => 'product'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conditions');
        $this->postJson('/api/loyalty-rules', $base + ['type' => 'product', 'conditions' => ['ids' => [$otherProduct->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conditions.ids.0');
        $this->postJson('/api/loyalty-rules', $base + ['type' => 'category', 'conditions' => ['ids' => [$otherCategory->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conditions.ids.0');
        $this->postJson('/api/loyalty-rules', $base + ['type' => 'product', 'conditions' => ['ids' => [$localProduct->id]]])->assertCreated();
        $this->postJson('/api/loyalty-rules', $base + ['type' => 'category', 'conditions' => ['ids' => [$localCategory->id]]])->assertCreated();
    }

    public function test_redemption_value_is_calculated_server_side(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente canje', 'phone' => '666']);
        LoyaltyTransaction::create(['customer_id' => $customer->id, 'type' => 'adjustment', 'points' => 5, 'remaining_points' => 5]);
        $order = $this->order($admin->branch_id, $cashier, $customer, 4);
        $order->update(['subtotal' => 10, 'total' => 10]);

        Sanctum::actingAs($cashier);
        $response = $this->postJson("/api/customers/{$customer->id}/redeem-points", [
            'points' => 2,
            'order_id' => $order->id,
            'value' => 999,
        ])->assertCreated();

        $this->assertDatabaseHas('loyalty_redemptions', ['id' => $response->json('id'), 'points' => 2, 'value' => 2]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'discount' => 2, 'total' => 8]);
    }

    public function test_audits_are_written_and_returned_only_for_the_current_branch(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $otherBranch = Branch::create(['name' => 'Sucursal auditoría', 'code' => 'AUDIT']);
        $otherAdmin = User::create([
            'name' => 'Admin externo',
            'email' => 'admin-audit@test.local',
            'password' => 'password123',
            'role_id' => Role::where('slug', 'administrador')->value('id'),
            'branch_id' => $otherBranch->id,
            'active' => true,
        ]);
        AuditLog::query()->delete();

        Sanctum::actingAs($admin);
        $this->putJson('/api/settings', ['settings' => ['pending_payment_minutes' => 15]])->assertOk();
        $localLog = AuditLog::where('auditable_type', Setting::class)->where('branch_id', $admin->branch_id)->firstOrFail();

        Sanctum::actingAs($otherAdmin);
        $this->putJson('/api/settings', ['settings' => ['pending_payment_minutes' => 25]])->assertOk();
        $otherLog = AuditLog::where('auditable_type', Setting::class)->where('branch_id', $otherBranch->id)->firstOrFail();

        $otherResponse = $this->getJson('/api/audit-logs')->assertOk();
        $this->assertSame([$otherBranch->id], collect($otherResponse->json('data'))->pluck('branch_id')->unique()->values()->all());
        $this->assertNotContains($localLog->id, collect($otherResponse->json('data'))->pluck('id')->all());

        Sanctum::actingAs($admin);
        $localResponse = $this->getJson('/api/audit-logs')->assertOk();
        $this->assertSame([$admin->branch_id], collect($localResponse->json('data'))->pluck('branch_id')->unique()->values()->all());
        $this->assertNotContains($otherLog->id, collect($localResponse->json('data'))->pluck('id')->all());
    }

    public function test_inactive_accounts_and_branches_are_rejected_and_tokens_are_revoked(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $managed = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $managedToken = $managed->createToken('managed-test')->accessToken;

        Sanctum::actingAs($admin);
        $this->putJson("/api/users/{$managed->id}", ['active' => false])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $managedToken->id]);
        $this->putJson("/api/users/{$admin->id}", ['active' => false])->assertUnprocessable();

        $otherBranch = Branch::create(['name' => 'Sucursal inactiva', 'code' => 'INACTIVA']);
        $branchUser = User::create([
            'name' => 'Usuario sucursal inactiva',
            'email' => 'inactive-branch@test.local',
            'password' => 'password123',
            'role_id' => Role::where('slug', 'cajero')->value('id'),
            'branch_id' => $otherBranch->id,
            'active' => true,
        ]);
        $branchToken = $branchUser->createToken('inactive-branch-test')->accessToken;
        $otherBranch->update(['active' => false]);

        Sanctum::actingAs($branchUser);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $branchToken->id]);
    }

    private function order(int $branchId, User $user, Customer $customer, int $dailyNumber): Order
    {
        return Order::create([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'order_date' => today(),
            'daily_number' => $dailyNumber,
            'status' => 'draft',
            'type' => 'pickup',
        ]);
    }
}
