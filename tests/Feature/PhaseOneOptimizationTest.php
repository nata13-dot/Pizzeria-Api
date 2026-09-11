<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchSettings;
use App\Services\ReceiptService;
use App\Services\RecipeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseOneOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_settings_are_loaded_once_and_can_be_invalidated(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        Setting::updateOrCreate(['branch_id' => $user->branch_id, 'key' => 'half_and_half_extra'], ['value' => 17]);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "settings"')) {
                $queries++;
            }
        });
        $settings = app(BranchSettings::class);

        $this->assertSame(17, $settings->get($user->branch_id, 'half_and_half_extra'));
        $this->assertSame(2, $settings->integer($user->branch_id, 'max_wing_flavors'));
        $this->assertSame(1, $queries);
        $settings->forget($user->branch_id);
        $settings->get($user->branch_id, 'half_and_half_extra');
        $this->assertSame(2, $queries);
    }

    public function test_pos_catalog_preserves_pos_fields_without_recipe_graph_and_reports_payload_metrics(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $unit = Unit::where('symbol', 'pz')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Medición']);
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Catálogo medido', 'type' => 'other', 'image_data_uri' => 'data:image/png;base64,'.str_repeat('A', 4000)]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 75]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Interna']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 2, 'component' => 'base']);
        config()->set('performance.enabled', true);
        config()->set('performance.log', false);

        $legacy = $this->getJson('/api/products')->assertOk();
        $compact = $this->getJson('/api/pos/catalog')->assertOk();
        $compactProduct = collect($compact->json())->firstWhere('id', $product->id);
        $this->assertSame('Catálogo medido', $compactProduct['name']);
        $this->assertArrayNotHasKey('recipes', $compactProduct['variants'][0]);
        $this->assertSame($product->image_data_uri, $compactProduct['image_data_uri']);
        $legacyMetrics = json_decode((string) base64_decode($legacy->headers->get('X-Performance-Metrics')), true);
        $compactMetrics = json_decode((string) base64_decode($compact->headers->get('X-Performance-Metrics')), true);

        $this->assertLessThan($legacyMetrics['response_bytes'], $compactMetrics['response_bytes']);
        $this->assertLessThan($legacyMetrics['query_count'], $compactMetrics['query_count']);
        $this->assertSame($legacyMetrics['image_data_uri_bytes'], $compactMetrics['image_data_uri_bytes']);
    }

    public function test_eager_and_lazy_recipe_resolution_produce_identical_snapshots(): void
    {
        $this->seed();
        $user = User::firstOrFail();
        $unit = Unit::where('symbol', 'pz')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Snapshot']);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Snapshot producto', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 20]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Snapshot receta']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1.25, 'component' => 'base']);

        $lazy = (new RecipeResolver(new BranchSettings))->resolve($variant->fresh());
        $eagerVariant = ProductVariant::with(['product.flavors', 'recipes.items.ingredient', 'modifierRules.modifier.items.ingredient'])->findOrFail($variant->id);
        $eager = (new RecipeResolver(new BranchSettings))->resolve($eagerVariant);

        $this->assertSame($lazy, $eager);
    }

    public function test_equivalent_ticket_is_reused_and_historical_reports_are_bounded(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $order = Order::create([
            'branch_id' => $admin->branch_id, 'user_id' => $admin->id, 'order_date' => now()->toDateString(),
            'daily_number' => 999, 'status' => 'draft', 'type' => 'pickup', 'subtotal' => 0, 'total' => 0,
        ]);
        $service = app(ReceiptService::class);
        $first = $service->generate($order, 'customer_html', $admin);
        $second = $service->generate($order->fresh(), 'customer_html', $admin);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, OrderDocument::where('order_id', $order->id)->count());
        $this->getJson('/api/reports/products?from=2020-01-01&to=2022-01-01')->assertUnprocessable();
    }

    public function test_required_phase_one_endpoints_expose_lightweight_metrics_when_enabled(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        config()->set('performance.enabled', true);
        config()->set('performance.log', false);
        $unit = Unit::where('symbol', 'pz')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Métrica endpoints']);
        InventoryBatch::create([
            'branch_id' => $admin->branch_id, 'ingredient_id' => $ingredient->id,
            'received_at' => now()->toDateString(), 'initial_quantity' => 20, 'available_quantity' => 20, 'unit_cost' => 1,
        ]);
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Producto métrica', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 20]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Receta métrica']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'component' => 'base']);

        $created = $this->postJson('/api/orders', [
            'status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Métrica', 'contact_phone' => '5551234567',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertCreated();
        $orderId = $created->json('id');
        $responses = [
            $created,
            $this->postJson("/api/orders/{$orderId}/send-to-kitchen")->assertOk(),
            $this->getJson('/api/products')->assertOk(),
            $this->getJson('/api/kitchen/orders')->assertOk(),
            $this->getJson('/api/orders?date='.now()->toDateString())->assertOk(),
            $this->getJson('/api/reports/products')->assertOk(),
            $this->getJson('/api/reports/profit')->assertOk(),
            $this->getJson('/api/reports/times')->assertOk(),
        ];

        foreach ($responses as $response) {
            $metrics = json_decode((string) base64_decode($response->headers->get('X-Performance-Metrics')), true);
            $this->assertIsArray($metrics);
            $this->assertArrayHasKey('duration_ms', $metrics);
            $this->assertArrayHasKey('query_count', $metrics);
            $this->assertArrayHasKey('query_time_ms', $metrics);
            $this->assertArrayHasKey('peak_memory_bytes', $metrics);
            $this->assertArrayHasKey('response_bytes', $metrics);
        }
    }
}
