<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Combo;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_and_logically_deactivate_the_complete_product_catalog(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $ingredient = $this->ingredient($admin, 'Queso catálogo');

        $category = $this->postJson('/api/product-categories', [
            'name' => 'Pizzas especiales',
            'sort_order' => 2,
        ])->assertCreated()->json();

        $product = $this->postJson('/api/products', [
            'product_category_id' => $category['id'],
            'name' => 'Pizza catálogo',
            'type' => 'pizza',
            'description' => 'Pizza con queso y especialidades de la casa.',
            'image_data_uri' => 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),
            'variants' => [
                ['name' => 'Mediana', 'sku' => 'PIZ-CAT-M', 'price' => 150, 'max_flavors' => 2, 'allows_half_and_half' => true],
                ['name' => 'Grande', 'sku' => 'PIZ-CAT-G', 'price' => 190, 'max_flavors' => 2, 'allows_half_and_half' => true],
            ],
            'flavors' => [['name' => 'Queso', 'ingredient_id' => $ingredient->id]],
        ])->assertCreated()
            ->assertJsonCount(2, 'variants')
            ->assertJsonPath('description', 'Pizza con queso y especialidades de la casa.')
            ->assertJsonPath('image_data_uri', fn ($value) => is_string($value) && str_starts_with($value, 'data:image/png;base64,'))
            ->json();

        $this->patchJson("/api/products/{$product['id']}", ['description' => 'Descripción corregida'])
            ->assertOk()
            ->assertJsonPath('description', 'Descripción corregida');
        $this->patchJson("/api/products/{$product['id']}", ['image_data_uri' => null])
            ->assertOk()
            ->assertJsonPath('image_data_uri', null);
        $this->patchJson("/api/products/{$product['id']}", ['product_category_id' => null])
            ->assertOk()
            ->assertJsonPath('product_category_id', null);
        $this->deleteJson("/api/product-categories/{$category['id']}")->assertNoContent();
        $this->patchJson("/api/product-variants/{$product['variants'][1]['id']}", ['price' => 205])
            ->assertOk()
            ->assertJsonPath('price', '205.00');

        $recipe = $this->postJson('/api/recipes', [
            'product_variant_id' => $product['variants'][0]['id'],
            'product_flavor_id' => $product['flavors'][0]['id'],
            'name' => 'Receta queso',
            'items' => [[
                'ingredient_id' => $ingredient->id,
                'quantity' => 100,
                'component' => 'topping',
            ]],
        ])->assertCreated()->json();

        $this->patchJson("/api/recipes/{$recipe['id']}", [
            'name' => 'Receta queso ajustada',
            'items' => [[
                'ingredient_id' => $ingredient->id,
                'quantity' => 110,
                'component' => 'topping',
            ]],
        ])->assertOk()->assertJsonPath('items.0.quantity', '110.0000');
        $this->deleteJson("/api/recipes/{$recipe['id']}")->assertNoContent();
        $this->deleteJson("/api/recipes/{$recipe['id']}")->assertNoContent();
        $this->deleteJson("/api/product-flavors/{$product['flavors'][0]['id']}")->assertNoContent();
        $this->deleteJson("/api/product-variants/{$product['variants'][0]['id']}")->assertNoContent();
        $this->deleteJson("/api/products/{$product['id']}")->assertNoContent();
        $this->deleteJson("/api/product-categories/{$category['id']}")->assertNoContent();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/products?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('0.id', $product['id'])
            ->assertJsonPath('0.active', false);
        $this->assertDatabaseHas('recipes', ['id' => $recipe['id'], 'active' => false]);
        $this->assertDatabaseHas('product_flavors', ['id' => $product['flavors'][0]['id'], 'active' => false]);
        $this->assertDatabaseHas('product_variants', ['id' => $product['variants'][0]['id'], 'active' => false]);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'active' => false]);
    }

    public function test_catalog_resources_and_payload_references_are_isolated_by_branch(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $other = Branch::create(['name' => 'Catálogo externo', 'code' => 'CAT-EXT']);
        $category = ProductCategory::create(['branch_id' => $other->id, 'name' => 'Externa']);
        $product = Product::create(['branch_id' => $other->id, 'product_category_id' => $category->id, 'name' => 'Producto externo', 'type' => 'pizza']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 100]);
        $flavor = ProductFlavor::create(['product_id' => $product->id, 'name' => 'Externo']);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'product_flavor_id' => $flavor->id, 'name' => 'Externa']);
        $modifier = Modifier::create(['branch_id' => $other->id, 'name' => 'Externo', 'type' => 'instruction', 'price' => 0]);
        $combo = Combo::create(['branch_id' => $other->id, 'name' => 'Combo externo', 'price' => 90]);
        $combo->allItems()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);

        Sanctum::actingAs($admin);
        foreach ([
            "/api/product-categories/{$category->id}",
            "/api/products/{$product->id}",
            "/api/recipes/{$recipe->id}",
            "/api/modifiers/{$modifier->id}",
            "/api/combos/{$combo->id}",
        ] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->patchJson("/api/product-variants/{$variant->id}", ['price' => 1])->assertNotFound();
        $this->patchJson("/api/product-flavors/{$flavor->id}", ['name' => 'Intento'])->assertNotFound();
        $this->postJson("/api/product-variants/{$variant->id}/modifiers", ['modifier_id' => $modifier->id])->assertNotFound();

        $this->postJson('/api/products', [
            'product_category_id' => $category->id,
            'name' => 'Producto inválido',
            'type' => 'other',
            'variants' => [['name' => 'Única', 'price' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('product_category_id');
        $this->postJson('/api/combos', [
            'name' => 'Combo inválido',
            'price' => 10,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_combo_updates_deactivate_removed_components_without_breaking_order_history(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $ingredient = $this->ingredient($admin, 'Extra combo');
        $product = Product::create(['branch_id' => $admin->branch_id, 'name' => 'Producto combo', 'type' => 'other']);
        $first = ProductVariant::create(['product_id' => $product->id, 'name' => 'Primera', 'price' => 60]);
        $second = ProductVariant::create(['product_id' => $product->id, 'name' => 'Segunda', 'price' => 70]);

        $modifier = $this->postJson('/api/modifiers', [
            'name' => 'Extra combo',
            'type' => 'add',
            'price' => 5,
            'items' => [['ingredient_id' => $ingredient->id, 'quantity' => 10]],
        ])->assertCreated()->json();
        $this->postJson("/api/product-variants/{$first->id}/modifiers", [
            'modifier_id' => $modifier['id'],
        ])->assertCreated();

        $combo = $this->postJson('/api/combos', [
            'name' => 'Combo configurable',
            'price' => 95,
            'items' => [[
                'product_variant_id' => $first->id,
                'quantity' => 1,
                'options' => [['modifier_id' => $modifier['id']]],
            ]],
        ])->assertCreated()->json();
        $oldItemId = $combo['items'][0]['id'];

        $order = Order::create([
            'branch_id' => $admin->branch_id,
            'user_id' => $admin->id,
            'order_date' => today(),
            'daily_number' => 1,
            'status' => 'delivered',
            'type' => 'pickup',
        ]);
        $orderItem = $order->items()->create([
            'combo_id' => $combo['id'],
            'name' => 'Combo configurable',
            'quantity' => 1,
            'unit_price' => 95,
            'total' => 95,
        ]);
        $component = $orderItem->components()->create([
            'combo_item_id' => $oldItemId,
            'product_variant_id' => $first->id,
            'name' => 'Producto combo Primera',
            'quantity' => 1,
        ]);

        $updated = $this->putJson("/api/combos/{$combo['id']}", [
            'name' => 'Combo configurable actualizado',
            'price' => 105,
            'items' => [[
                'product_variant_id' => $second->id,
                'quantity' => 1,
            ]],
        ])->assertOk()->assertJsonCount(2, 'items')->json();
        $newItem = collect($updated['items'])->firstWhere('active', true);

        $this->assertDatabaseHas('combo_items', ['id' => $oldItemId, 'active' => false]);
        $this->assertDatabaseHas('order_item_components', ['id' => $component->id, 'combo_item_id' => $oldItemId]);
        $this->getJson('/api/combos')
            ->assertOk()
            ->assertJsonCount(1, '0.items')
            ->assertJsonPath('0.items.0.id', $newItem['id']);
        $this->getJson('/api/combos?include_inactive=1')->assertOk()->assertJsonCount(2, '0.items');
        $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'pickup',
            'items' => [[
                'combo_id' => $combo['id'],
                'quantity' => 1,
                'components' => [['combo_item_id' => $oldItemId]],
            ]],
            'payments' => [['method' => 'cash', 'amount' => 105]],
        ])->assertUnprocessable();

        $this->deleteJson("/api/product-variants/{$first->id}")->assertNoContent();
        $this->deleteJson("/api/modifiers/{$modifier['id']}")->assertNoContent();
    }

    public function test_active_catalog_dependencies_and_recipe_uniqueness_are_validated(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $ingredient = $this->ingredient($admin, 'Ingrediente validación');
        $category = ProductCategory::create(['branch_id' => $admin->branch_id, 'name' => 'Categoría protegida']);
        $product = Product::create(['branch_id' => $admin->branch_id, 'product_category_id' => $category->id, 'name' => 'Producto protegido', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 50]);

        $this->deleteJson("/api/product-categories/{$category->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('active');
        $this->deleteJson("/api/product-variants/{$variant->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('active');

        $payload = [
            'product_variant_id' => $variant->id,
            'name' => 'Base',
            'items' => [[
                'ingredient_id' => $ingredient->id,
                'quantity' => 1,
                'component' => 'base',
            ]],
        ];
        $this->postJson('/api/recipes', $payload)->assertCreated();
        $this->postJson('/api/recipes', $payload + ['name' => 'Base duplicada'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_flavor_id');

        $duplicateItems = $payload;
        $duplicateItems['product_variant_id'] = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Segunda',
            'price' => 70,
        ])->id;
        $duplicateItems['items'][] = $duplicateItems['items'][0];
        $this->postJson('/api/recipes', $duplicateItems)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.ingredient_id');
    }

    public function test_operational_users_only_see_active_catalog_and_cannot_request_admin_history(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        $cashier = User::where('email', 'cajero@pizzeria.local')->firstOrFail();
        $kitchen = User::where('email', 'cocina@pizzeria.local')->firstOrFail();
        Product::create(['branch_id' => $admin->branch_id, 'name' => 'Activo', 'type' => 'other', 'active' => true]);
        Product::create(['branch_id' => $admin->branch_id, 'name' => 'Inactivo', 'type' => 'other', 'active' => false]);

        Sanctum::actingAs($cashier);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Activo');
        $this->getJson('/api/products?include_inactive=1')->assertForbidden();
        $this->postJson('/api/product-categories', ['name' => 'No permitida'])->assertForbidden();

        Sanctum::actingAs($kitchen);
        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/product-categories')->assertForbidden();
        $this->getJson('/api/modifiers')->assertForbidden();
        $this->getJson('/api/combos')->assertForbidden();
    }

    private function ingredient(User $user, string $name): Ingredient
    {
        return Ingredient::create([
            'branch_id' => $user->branch_id,
            'base_unit_id' => Unit::where('symbol', 'g')->value('id'),
            'name' => $name,
        ]);
    }
}
