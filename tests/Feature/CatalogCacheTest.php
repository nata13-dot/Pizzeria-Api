<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are needed here to exercise cross-request cache behavior.
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
        config(['performance.catalog_cache_store' => 'array', 'performance.catalog_cache_enabled' => true]);
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@pizzeria.local')->firstOrFail());
    }

    public function test_warm_catalog_avoids_catalog_queries_and_price_changes_invalidate_it(): void
    {
        $product = Product::create(['branch_id' => auth()->user()->branch_id, 'name' => 'Cache pizza', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Normal', 'price' => 10]);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (preg_match('/from "(products|product_variants|product_flavors|product_categories|product_modifier_rules|modifiers)"/', $query->sql)) {
                $queries++;
            }
        });
        $first = $this->getJson('/api/pos/catalog')->assertOk()->json();
        $this->assertGreaterThan(0, $queries);
        $queries = 0;
        $this->assertSame($first, $this->getJson('/api/pos/catalog')->assertOk()->json());
        $this->assertSame(0, $queries, 'Warm catalog should execute no catalog queries');

        $variant->update(['price' => 25]);
        $next = collect($this->getJson('/api/pos/catalog')->assertOk()->json())->firstWhere('id', $product->id);
        $this->assertSame('25.00', $next['variants'][0]['price']);
    }

    public function test_cache_preserves_branch_isolation_expiration_and_does_not_publish_transactions(): void
    {
        $cache = app(CatalogCache::class);
        $this->assertSame(['a'], $cache->remember(1, 'test', fn () => ['a']));
        $this->assertSame(['b'], $cache->remember(2, 'test', fn () => ['b']));
        DB::beginTransaction();
        $this->assertSame(['uncommitted'], $cache->remember(1, 'test', fn () => ['uncommitted']));
        DB::rollBack();
        $this->assertSame(['a'], $cache->remember(1, 'test', fn () => ['unexpected']));
        $this->travel(301)->seconds();
        $this->assertSame(['expired'], $cache->remember(1, 'test', fn () => ['expired']));
    }

    public function test_a_mutation_during_loading_cannot_repopulate_the_cache(): void
    {
        $cache = app(CatalogCache::class);
        $cache->remember(1, 'race', function () use ($cache) {
            $cache->invalidate();

            return ['old'];
        });
        $this->assertSame(['new'], $cache->remember(1, 'race', fn () => ['new']));
        $this->assertSame(['new'], $cache->remember(1, 'race', fn () => ['unexpected']));
    }

    public function test_detaching_a_modifier_invalidates_the_warm_catalog(): void
    {
        $branchId = auth()->user()->branch_id;
        $product = Product::create(['branch_id' => $branchId, 'name' => 'Extra test', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Normal', 'price' => 10]);
        $modifier = Modifier::create(['branch_id' => $branchId, 'name' => 'Extra', 'type' => 'add', 'price' => 2]);
        $variant->modifierRules()->create(['modifier_id' => $modifier->id, 'allowed' => true]);
        $this->getJson('/api/pos/catalog')->assertOk();
        $this->deleteJson("/api/product-variants/{$variant->id}/modifiers/{$modifier->id}")->assertNoContent();
        $next = collect($this->getJson('/api/pos/catalog')->assertOk()->json())->firstWhere('id', $product->id);
        $this->assertSame([], $next['variants'][0]['modifier_rules']);
    }

    public function test_warm_cache_does_not_bypass_permissions_and_uses_fixed_keys(): void
    {
        $this->getJson('/api/pos/catalog')->assertOk();
        Sanctum::actingAs(User::where('email', 'cocina@pizzeria.local')->firstOrFail());
        $this->getJson('/api/pos/catalog')->assertForbidden();
        $cache = app(CatalogCache::class);
        for ($i = 0; $i < 10; $i++) {
            $cache->invalidate();
            $cache->remember(999, 'fixed', fn () => [$i]);
        }
        $keys = array_keys(Cache::store('array')->getStore()->all());
        $this->assertCount(1, array_filter($keys, fn ($key) => str_contains($key, '999:fixed')));
    }
}
