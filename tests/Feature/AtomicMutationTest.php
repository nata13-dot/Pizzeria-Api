<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ComboAllowedOption;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchClock;
use App\Services\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AtomicMutationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_individual_catalog_and_customer_writes_roll_back_if_audit_fails(): void
    {
        $admin = $this->admin();
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Original', 'type' => 'other']);
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Ana', 'phone' => '123']);
        $injected = 0;
        AuditLog::creating(function () use (&$injected) {
            $injected++;
            throw new \RuntimeException('Audit failure');
        });
        $this->patchJson('/api/products/'.$product->id, ['name' => 'Cambio'])->assertStatus(500);
        $this->putJson('/api/customers/'.$customer->id, ['name' => 'Cambio'])->assertStatus(500);
        $this->assertSame(2, $injected);
        $this->assertSame('Original', $product->fresh()->name);
        $this->assertSame('Ana', $customer->fresh()->name);
    }

    public function test_address_creation_and_delete_restore_the_default_after_intermediate_failure(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Ana', 'phone' => '123']);
        $first = $customer->addresses()->create(['label' => 'Casa', 'address' => 'Centro', 'is_default' => true]);
        $injected = false;
        CustomerAddress::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Address failure');
        });
        $this->postJson('/api/customers/'.$customer->id.'/addresses', ['label' => 'Trabajo', 'address' => 'Norte', 'is_default' => true])->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertTrue($first->fresh()->is_default);
        $this->assertSame(1, $customer->addresses()->count());
        CustomerAddress::deleted(function () {
            throw new \RuntimeException('Delete failure');
        });
        $this->deleteJson('/api/customers/'.$customer->id.'/addresses/'.$first->id)->assertStatus(500);
        $this->assertTrue($first->fresh()->is_default);
    }

    public function test_purchase_failure_after_inventory_movement_restores_all_tables(): void
    {
        $admin = $this->admin();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Harina']);
        $presentation = $this->postJson("/api/ingredients/{$ingredient->id}/presentations", ['name' => 'Bolsa', 'quantity' => 100, 'equivalent_unit_id' => $unit->id])->assertCreated()->json();
        $auditCount = AuditLog::count();
        $injected = false;
        InventoryMovement::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Movement failure');
        });
        $this->postJson('/api/purchases', ['purchased_at' => app(BranchClock::class)->today($admin->branch_id)->toDateString(), 'payment_source' => 'owner', 'items' => [['ingredient_presentation_id' => $presentation['id'], 'presentations_quantity' => 2, 'total_cost' => 50]]])->assertStatus(500);
        $this->assertTrue($injected);
        foreach (['purchases', 'purchase_items', 'inventory_batches', 'inventory_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_production_failure_after_consumption_restores_stock(): void
    {
        $admin = $this->admin();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $input = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Harina']);
        $output = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Masa']);
        $batch = InventoryBatch::create(['branch_id' => $admin->branch_id, 'ingredient_id' => $input->id, 'received_at' => today(), 'initial_quantity' => 1000, 'available_quantity' => 1000]);
        $recipe = $this->postJson('/api/production-recipes', ['name' => 'Masa', 'output_ingredient_id' => $output->id, 'yield_quantity' => 1000, 'yield_unit_id' => $unit->id, 'shelf_life_days' => 1, 'items' => [['ingredient_id' => $input->id, 'quantity' => 600]]])->assertCreated()->json();
        $injected = false;
        InventoryMovement::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Consumption failure');
        });
        $this->postJson('/api/production-batches', ['production_recipe_id' => $recipe['id'], 'multiplier' => 1])->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertEquals(1000, $batch->fresh()->available_quantity);
        $this->assertDatabaseCount('production_batches', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_batches', 1);
    }

    public function test_recipe_replacement_failure_preserves_previous_items(): void
    {
        $admin = $this->admin();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Queso']);
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Pizza', 'type' => 'pizza']);
        $variant = $product->variants()->create(['name' => 'Única', 'price' => 100]);
        $recipe = $this->postJson('/api/recipes', ['product_variant_id' => $variant->id, 'name' => 'Base', 'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 20, 'component' => 'base']]])->assertCreated()->json();
        $injected = false;
        RecipeItem::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Recipe failure');
        });
        $this->putJson('/api/recipes/'.$recipe['id'], ['name' => 'Cambio', 'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 30, 'component' => 'base']]])->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertDatabaseHas('recipes', ['id' => $recipe['id'], 'name' => 'Base']);
        $this->assertDatabaseHas('recipe_items', ['id' => $recipe['items'][0]['id'], 'quantity' => 20]);
    }

    public function test_combo_option_replacement_failure_keeps_previous_configuration(): void
    {
        $admin = $this->admin();
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Producto', 'type' => 'other']);
        $variant = $product->variants()->create(['name' => 'Única', 'price' => 100]);
        $flavor = $product->flavors()->create(['name' => 'BBQ']);
        $payload = ['name' => 'Combo', 'price' => 80, 'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'options' => [['product_flavor_id' => $flavor->id]]]]];
        $combo = $this->postJson('/api/combos', $payload)->assertCreated()->json();
        $payload['name'] = 'Cambio';
        $payload['items'][0]['id'] = $combo['items'][0]['id'];
        $injected = false;
        ComboAllowedOption::created(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Option failure');
        });
        $this->putJson('/api/combos/'.$combo['id'], $payload)->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertDatabaseHas('combos', ['id' => $combo['id'], 'name' => 'Combo']);
        $this->assertDatabaseHas('combo_allowed_options', ['id' => $combo['items'][0]['options'][0]['id']]);
    }

    public function test_uncommitted_catalog_read_is_not_published_to_cache(): void
    {
        $this->admin();
        config(['performance.catalog_cache_enabled' => true, 'performance.catalog_cache_store' => 'array']);
        $cache = app(CatalogCache::class);
        Cache::store('array')->flush();
        try {
            DB::transaction(function () use ($cache) {
                $this->assertSame(['partial'], $cache->remember(1, 'test', fn () => ['partial']));
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull(Cache::store('array')->get('catalog:v1:1:test'));
    }

    public function test_changing_default_address_rolls_back_both_addresses_on_failure(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Ana', 'phone' => '123']);
        $first = $customer->addresses()->create(['label' => 'Casa', 'address' => 'Centro', 'is_default' => true]);
        $second = $customer->addresses()->create(['label' => 'Trabajo', 'address' => 'Norte', 'is_default' => false]);
        $injected = false;
        CustomerAddress::updated(function () use (&$injected) {
            $injected = true;
            throw new \RuntimeException('Default failure');
        });
        $this->putJson('/api/customers/'.$customer->id.'/addresses/'.$second->id, ['is_default' => true])->assertStatus(500);
        $this->assertTrue($injected);
        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }
}
