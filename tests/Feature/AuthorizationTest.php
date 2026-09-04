<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_always_has_every_permission_and_it_cannot_be_stripped_from_role_editor(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $role = Role::where('slug', 'administrador')->firstOrFail();
        $permissionCount = Permission::count();

        $this->assertTrue($permissionCount > 0);
        $this->assertCount($permissionCount, $role->permissions);
        foreach (Permission::pluck('slug') as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
        }

        Sanctum::actingAs($admin);
        $this->putJson("/api/roles/{$role->id}", [
            'permission_ids' => [Permission::query()->value('id')],
        ])->assertOk()->assertJsonCount($permissionCount, 'permissions');

        $this->assertCount($permissionCount, $role->fresh()->permissions);
    }

    public function test_cashier_cannot_change_settings_or_inventory_base(): void
    {
        $this->seed();
        $cashier = User::create(['name' => 'Caja', 'email' => 'caja@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'cajero')->value('id'), 'branch_id' => User::first()->branch_id]);
        Sanctum::actingAs($cashier);
        $this->putJson('/api/settings', ['settings' => ['half_extra' => 20]])->assertForbidden();
        $this->postJson('/api/ingredients', ['name' => 'No permitido', 'base_unit_id' => 1])->assertForbidden();
        $this->getJson('/api/ingredients')->assertOk();
        $this->getJson('/api/purchases')->assertForbidden();
        $this->getJson('/api/production-recipes')->assertForbidden();
    }

    public function test_every_user_can_manage_only_their_own_receipt_font_preference(): void
    {
        $this->seed();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/preferences')->assertOk()->assertJsonPath('receipt_font_size', 'small');
        $this->putJson('/api/preferences', ['receipt_font_size' => 'large'])
            ->assertOk()->assertJsonPath('receipt_font_size', 'large');
        $this->putJson('/api/preferences', ['receipt_font_size' => 'enorme'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_font_size');

        $this->assertSame('large', $cashier->fresh()->receipt_font_size);
        $this->assertSame('small', $admin->fresh()->receipt_font_size);
    }

    public function test_kitchen_and_delivery_have_separate_operational_access(): void
    {
        $this->seed();
        $branch = User::first()->branch_id;
        $kitchen = User::create(['name' => 'Cocina', 'email' => 'cocina@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'cocina')->value('id'), 'branch_id' => $branch]);
        Sanctum::actingAs($kitchen);
        $this->getJson('/api/kitchen/orders')->assertOk();
        $this->getJson('/api/delivery/orders')->assertForbidden();
        $delivery = User::create(['name' => 'Reparto', 'email' => 'reparto@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'repartidor')->value('id'), 'branch_id' => $branch]);
        Sanctum::actingAs($delivery);
        $this->getJson('/api/delivery/orders')->assertOk();
        $this->getJson('/api/kitchen/orders')->assertForbidden();
    }

    public function test_cashier_cannot_create_orders_with_records_from_another_branch(): void
    {
        $this->seed();
        $cashier = User::create(['name' => 'Caja', 'email' => 'caja-branch@test.local', 'password' => 'password', 'role_id' => Role::where('slug', 'cajero')->value('id'), 'branch_id' => User::first()->branch_id]);
        $other = Branch::create(['name' => 'Otra', 'code' => 'OTRA']);
        $customer = Customer::create(['branch_id' => $other->id, 'name' => 'Cliente externo', 'phone' => '555']);
        $product = Product::create(['branch_id' => $other->id, 'name' => 'Producto externo', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 100]);
        Sanctum::actingAs($cashier);
        $payload = ['status' => 'confirmed', 'type' => 'pickup', 'customer_id' => $customer->id, 'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]]];
        $this->postJson('/api/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        unset($payload['customer_id']);
        $this->postJson('/api/orders', $payload)->assertNotFound();
    }

    public function test_users_are_created_and_managed_only_in_the_admin_branch(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $roleId = Role::where('slug', 'cajero')->value('id');

        $created = $this->postJson('/api/users', [
            'name' => 'Caja nueva',
            'email' => 'caja-nueva@test.local',
            'password' => 'password123',
            'role_id' => $roleId,
        ])->assertCreated()->assertJsonPath('branch_id', $admin->branch_id)->json();

        $this->postJson('/api/users', [
            'name' => 'Duplicado',
            'email' => 'caja-nueva@test.local',
            'password' => 'password123',
            'role_id' => $roleId,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $other = Branch::create(['name' => 'Otra sucursal', 'code' => 'OTRA-USER']);
        $outsider = User::create(['name' => 'Externo', 'email' => 'externo@test.local', 'password' => 'password123', 'role_id' => $roleId, 'branch_id' => $other->id]);
        $this->putJson("/api/users/{$outsider->id}", ['name' => 'Intento'])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $created['id'], 'branch_id' => $admin->branch_id]);
    }

    public function test_cashier_can_view_but_cannot_create_loyalty_rules(): void
    {
        $this->seed();
        $cashier = User::create(['name' => 'Caja puntos', 'email' => 'caja-puntos@test.local', 'password' => 'password123', 'role_id' => Role::where('slug', 'cajero')->value('id'), 'branch_id' => User::first()->branch_id]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/loyalty-rules')->assertOk();
        $this->postJson('/api/loyalty-rules', ['name' => 'No permitida', 'type' => 'per_order', 'threshold' => 1, 'points' => 1])->assertForbidden();
    }

    public function test_order_state_capabilities_follow_configured_permissions_instead_of_role_names(): void
    {
        $this->seed();
        $branchId = User::firstOrFail()->branch_id;
        $cashierRole = Role::where('slug', 'cajero')->firstOrFail();
        $cashierRole->permissions()->syncWithoutDetaching([
            Permission::where('slug', 'kitchen.use')->value('id'),
        ]);
        $cashier = User::create([
            'name' => 'Caja autorizada en cocina',
            'email' => 'caja-cocina@test.local',
            'password' => 'password123',
            'role_id' => $cashierRole->id,
            'branch_id' => $branchId,
        ]);
        $order = Order::create([
            'branch_id' => $branchId,
            'user_id' => User::firstOrFail()->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'kitchen_pending',
            'type' => 'pickup',
            'subtotal' => 100,
            'total' => 100,
        ]);

        Sanctum::actingAs($cashier);
        $this->postJson("/api/orders/{$order->id}/status", ['status' => 'preparing'])
            ->assertOk()
            ->assertJsonPath('status', 'preparing');
    }

    public function test_delivery_can_see_future_scheduled_orders_for_route_planning(): void
    {
        $this->seed();
        $delivery = User::where('email', 'repartidor@pizzeria.local')->firstOrFail();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $future = Order::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $admin->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'confirmed',
            'type' => 'delivery',
            'scheduled_at' => now()->addDays(2),
            'subtotal' => 100,
            'total' => 100,
        ]);

        Sanctum::actingAs($delivery);
        $this->getJson('/api/delivery/orders')
            ->assertOk()
            ->assertJsonPath('0.id', $future->id);
        $this->getJson('/api/delivery/orders?view=operational')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
