<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_lookup_is_read_only_and_retry_does_not_duplicate_order_or_payment(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Recuperable', 'type' => 'beverage']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Unidad', 'price' => 20]);
        Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Base']);
        $payload = ['status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Ana', 'contact_phone' => '5551234567', 'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 20]]];
        $this->getJson('/api/orders/operation?key=pos-recovery-test')->assertOk()->assertJsonPath('order', null);
        $first = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'pos-recovery-test'])->assertCreated();
        $id = $first->json('id');
        $this->getJson('/api/orders/operation?key=pos-recovery-test')->assertOk()->assertJsonPath('order.id', $id)->assertJsonPath('order.status', 'confirmed');
        $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'pos-recovery-test'])->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, Order::where('idempotency_key', 'pos-recovery-test')->count());
        $this->assertSame(1, Order::findOrFail($id)->payments()->count());
        $other = User::factory()->create(['branch_id' => $user->branch_id, 'role_id' => $user->role_id]);
        Sanctum::actingAs($other);
        $this->getJson('/api/orders/operation?key=pos-recovery-test')->assertOk()->assertJsonPath('order', null);
        $branch = Branch::create(['name' => 'Otra', 'code' => 'RECOVERY']);
        $user->update(['branch_id' => $branch->id]);
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/orders/operation?key=pos-recovery-test')->assertOk()->assertJsonPath('order', null);
    }

    public function test_pos_customer_lookup_is_scoped_and_does_not_expose_history(): void
    {
        $this->seed();
        $user = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $customer = Customer::create(['branch_id' => $user->branch_id, 'name' => 'Ana', 'phone' => '5551234567']);
        $customer->addresses()->create(['label' => 'Casa', 'address' => 'Centro']);
        $this->getJson('/api/pos/customers/'.$customer->id)->assertOk()->assertJsonPath('id', $customer->id)->assertJsonCount(1, 'addresses')->assertJsonMissingPath('orders')->assertJsonMissingPath('loyalty_transactions');
        $branch = Branch::create(['name' => 'Otra', 'code' => 'RECOVERY']);
        $customer->update(['branch_id' => $branch->id]);
        $this->getJson('/api/pos/customers/'.$customer->id)->assertNotFound();
        Sanctum::actingAs(User::where('email', 'cocina@pizzeria.local')->firstOrFail());
        $this->getJson('/api/orders/operation?key=pos-recovery-test')->assertForbidden();
    }
}
