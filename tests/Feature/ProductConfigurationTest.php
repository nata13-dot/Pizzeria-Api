<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Combo;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductConfigurationOperation;
use App\Models\ProductFlavor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function setupProduct(): array
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Configuración', 'type' => 'pizza']);
        $variant = $product->variants()->create(['name' => 'Única', 'price' => '125.00', 'max_flavors' => 2]);
        $path = "/api/products/{$product->id}/configuration";
        $revision = $this->getJson($path)->assertOk()->json('revision');

        return [$product, $path, [
            'expected_revision' => $revision, 'operation_key' => 'configuration-test-001',
            'product' => ['name' => 'Configuración nueva'],
            'variants' => [['id' => $variant->id, 'price' => 145, 'allows_stuffed_crust' => true]],
            'flavors' => [['name' => 'Nuevo sabor']], 'removed_variant_ids' => [], 'removed_flavor_ids' => [],
        ]];
    }

    public function test_configuration_is_atomic_and_replay_does_not_duplicate_flavors(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $this->putJson($path, $payload)->assertOk()->assertJsonPath('replayed', false);
        $this->putJson($path, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('product_configuration_operations', 1);
        $this->assertSame(1, $product->flavors()->count());
        $this->assertSame('145.00', $product->variants()->first()->price);
        $this->getJson($path.'/operation?key=configuration-test-001')->assertOk()->assertJsonPath('operation.product_id', $product->id);
        $payload['product']['name'] = 'Otro intento';
        $this->putJson($path, $payload)->assertStatus(409)->assertJsonPath('code', 'operation_conflict');
        $payload['operation_key'] = 'configuration-test-002';
        $this->putJson($path, $payload)->assertStatus(409)->assertJsonPath('code', 'configuration_conflict');
        $this->assertSame('Configuración nueva', $product->fresh()->name);
    }

    public function test_failure_after_flavor_creation_rolls_back_product_variant_audit_and_receipt(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $auditCount = AuditLog::count();
        $injected = false;
        ProductFlavor::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Injected failure');
        });
        $this->putJson($path, $payload)->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertSame('Configuración', $product->fresh()->name);
        $this->assertSame('125.00', $product->variants()->first()->price);
        $this->assertSame(0, $product->flavors()->count());
        $this->assertDatabaseCount('product_configuration_operations', 0);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_custom_crust_price_and_other_products_are_preserved(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $modifier = Modifier::create(['branch_id' => $product->branch_id, 'name' => 'Orilla especial', 'type' => 'stuffed_crust', 'price' => 37]);
        $variant = $product->variants()->first();
        $rule = $variant->modifierRules()->create(['modifier_id' => $modifier->id, 'allowed' => true, 'price_override' => 29]);
        $payload['expected_revision'] = $this->getJson($path)->json('revision');
        $this->putJson($path, $payload)->assertOk();
        $this->assertSame('37.00', $modifier->fresh()->price);
        $this->assertSame('29.00', $rule->fresh()->price_override);
    }

    public function test_failure_after_crust_rule_and_receipt_creation_rolls_back_everything(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $injected = false;
        ProductConfigurationOperation::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Receipt failure');
        });
        $this->putJson($path, $payload)->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertSame('Configuración', $product->fresh()->name);
        $this->assertDatabaseCount('product_configuration_operations', 0);
        $this->assertDatabaseCount('product_modifier_rules', 0);
        $this->assertDatabaseCount('modifiers', 0);
        $this->assertDatabaseCount('product_flavors', 0);
    }

    public function test_configuration_rejects_other_branch_references_and_unauthorized_users(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $other = Product::create(['branch_id' => $product->branch_id, 'name' => 'Otro', 'type' => 'other']);
        $variant = $other->variants()->create(['name' => 'Única', 'price' => 5]);
        $payload['variants'][0]['id'] = $variant->id;
        $this->putJson($path, $payload)->assertUnprocessable();
        Sanctum::actingAs(User::where('email', 'cajero@pizzeria.local')->firstOrFail());
        $this->getJson($path)->assertForbidden();
        $this->putJson($path, $payload)->assertForbidden();
        $this->getJson($path.'/operation?key=configuration-test-001')->assertForbidden();
    }

    public function test_numeric_false_cannot_bypass_active_combo_dependency_checks(): void
    {
        [$product, $path, $payload] = $this->setupProduct();
        $variant = $product->variants()->firstOrFail();
        $product->variants()->create(['name' => 'Alternativa', 'price' => 100]);
        $combo = Combo::create(['branch_id' => $product->branch_id, 'name' => 'Protegido', 'price' => 100]);
        $combo->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
        $payload['expected_revision'] = $this->getJson($path)->json('revision');
        $this->postJson('/api/products', ['name' => 'Sin variante activa', 'type' => 'other', 'variants' => [['name' => 'Única', 'price' => 1, 'active' => 0]]])->assertUnprocessable();
        $payload['variants'][0]['active'] = 0;
        $this->putJson($path, $payload)->assertUnprocessable();
        $this->assertTrue($variant->fresh()->active);
        $this->assertSame('Configuración', $product->fresh()->name);
        $this->assertDatabaseCount('product_configuration_operations', 0);
    }
}
