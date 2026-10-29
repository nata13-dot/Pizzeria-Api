<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductFlavor;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeBaseTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $this->seed();
        $user = User::first();
        Sanctum::actingAs($user);
        $grams = Unit::where('symbol', 'g')->first();
        $make = fn (string $name) => Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => $name]);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Pizza base', 'type' => 'pizza']);
        $medium = ProductVariant::create(['product_id' => $product->id, 'name' => 'Mediana', 'price' => 100, 'max_flavors' => 2, 'allows_half_and_half' => true]);
        $large = ProductVariant::create(['product_id' => $product->id, 'name' => 'Grande', 'price' => 150, 'max_flavors' => 2, 'allows_half_and_half' => true]);
        $pepperoni = ProductFlavor::create(['product_id' => $product->id, 'name' => 'Pepperoni']);
        $cheese = ProductFlavor::create(['product_id' => $product->id, 'name' => 'Queso']);

        return [$product, $medium, $large, $pepperoni, $cheese, $make('Masa'), $make('Pepperoni'), $make('Queso')];
    }

    private function resolved(ProductVariant $variant, array $flavors): array
    {
        return collect($this->postJson("/api/product-variants/{$variant->id}/resolve", ['flavor_ids' => $flavors])->assertOk()->json('ingredients'))
            ->mapWithKeys(fn ($row) => [$row['ingredient_id'] => $row['quantity']])->all();
    }

    public function test_shared_base_is_consumed_once_and_flavor_extras_are_split(): void
    {
        [, $medium, , $pepperoni, $cheese, $dough, $sausage] = $this->fixtures();
        $this->postJson('/api/recipes/batch', ['recipes' => [
            ['product_variant_id' => $medium->id, 'product_flavor_id' => null, 'applies_to_flavors' => true, 'name' => 'Base', 'items' => [['ingredient_id' => $dough->id, 'quantity' => 200, 'component' => 'base']]],
            ['product_variant_id' => $medium->id, 'product_flavor_id' => $pepperoni->id, 'name' => 'Pepperoni', 'items' => [['ingredient_id' => $sausage->id, 'quantity' => 50, 'component' => 'topping']]],
            ['product_variant_id' => $medium->id, 'product_flavor_id' => $cheese->id, 'base_only' => true, 'name' => 'Queso', 'items' => []],
        ]])->assertOk()->assertJsonCount(3, 'data');

        $this->assertEquals([$dough->id => 200, $sausage->id => 50], $this->resolved($medium, [$pepperoni->id]));
        $this->assertEquals([$dough->id => 200, $sausage->id => 25], $this->resolved($medium, [$pepperoni->id, $cheese->id]));
        $this->assertEquals([$dough->id => 200], $this->resolved($medium, [$cheese->id]));
        // A base for all flavors does not enable selling the pizza without a flavor.
        $this->postJson("/api/product-variants/{$medium->id}/resolve", ['flavor_ids' => []])->assertUnprocessable();
    }

    public function test_base_only_requires_an_active_base_and_the_base_cannot_be_removed_under_it(): void
    {
        [, $medium, , $pepperoni, $cheese, $dough] = $this->fixtures();
        $this->postJson('/api/recipes', ['product_variant_id' => $medium->id, 'product_flavor_id' => $cheese->id, 'base_only' => true, 'name' => 'Queso', 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('base_only');
        $this->postJson('/api/recipes', ['product_variant_id' => $medium->id, 'product_flavor_id' => $pepperoni->id, 'name' => 'Vacía', 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/recipes', ['product_variant_id' => $medium->id, 'product_flavor_id' => $pepperoni->id, 'applies_to_flavors' => true, 'name' => 'Mal', 'items' => [['ingredient_id' => $dough->id, 'quantity' => 1, 'component' => 'base']]])
            ->assertUnprocessable()->assertJsonValidationErrors('applies_to_flavors');

        $base = $this->postJson('/api/recipes', ['product_variant_id' => $medium->id, 'applies_to_flavors' => true, 'name' => 'Base', 'items' => [['ingredient_id' => $dough->id, 'quantity' => 200, 'component' => 'base']]])->assertCreated()->json('id');
        $this->postJson('/api/recipes', ['product_variant_id' => $medium->id, 'product_flavor_id' => $cheese->id, 'base_only' => true, 'name' => 'Queso', 'items' => []])->assertCreated();
        $this->deleteJson("/api/recipes/{$base}")->assertUnprocessable()->assertJsonValidationErrors('base_only');
        $this->assertTrue(Recipe::find($base)->active);
    }

    public function test_batch_saves_every_size_or_none(): void
    {
        [, $medium, $large, $pepperoni, , $dough, $sausage] = $this->fixtures();
        $entry = fn ($variant, $quantity) => ['product_variant_id' => $variant->id, 'product_flavor_id' => $pepperoni->id, 'name' => 'Pepperoni', 'items' => [['ingredient_id' => $sausage->id, 'quantity' => $quantity, 'component' => 'topping']]];

        $this->postJson('/api/recipes/batch', ['recipes' => [$entry($medium, 50), [...$entry($large, 80), 'items' => [['ingredient_id' => 999999, 'quantity' => 1, 'component' => 'topping']]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipes.1.items.0.ingredient_id');
        $this->assertDatabaseCount('recipes', 0);
        $this->postJson('/api/recipes/batch', ['recipes' => [$entry($medium, 50), $entry($medium, 60)]])->assertUnprocessable()->assertJsonValidationErrors('recipes.1.product_variant_id');

        $saved = $this->postJson('/api/recipes/batch', ['recipes' => [$entry($medium, 50), $entry($large, 80)]])->assertOk()->json('data');
        $this->assertDatabaseCount('recipes', 2);
        $this->postJson('/api/recipes/batch', ['recipes' => [[...$entry($medium, 55), 'id' => $saved[0]['id']], $entry($large, 90)]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipes.1.product_flavor_id');
        $this->assertEquals(50, Recipe::find($saved[0]['id'])->items()->first()->quantity);
        $this->postJson('/api/recipes/batch', ['recipes' => [[...$entry($medium, 55), 'id' => $saved[0]['id']], [...$entry($large, 90), 'id' => $saved[1]['id']]]])->assertOk();
        $this->assertEquals(90, Recipe::find($saved[1]['id'])->items()->first()->quantity);
    }

    public function test_base_split_keeps_single_flavor_consumption_and_rejects_stale_previews(): void
    {
        [$product, $medium, , $pepperoni, $cheese, $dough, $sausage, $mozzarella] = $this->fixtures();
        $make = fn ($flavor, $items) => Recipe::create(['product_variant_id' => $medium->id, 'product_flavor_id' => $flavor->id, 'name' => $flavor->name])->items()->createMany($items);
        $make($pepperoni, [['ingredient_id' => $dough->id, 'quantity' => 200, 'component' => 'base'], ['ingredient_id' => $mozzarella->id, 'quantity' => 100, 'component' => 'topping'], ['ingredient_id' => $sausage->id, 'quantity' => 50, 'component' => 'topping']]);
        $make($cheese, [['ingredient_id' => $dough->id, 'quantity' => 200, 'component' => 'base'], ['ingredient_id' => $mozzarella->id, 'quantity' => 140, 'component' => 'topping']]);
        $before = [$this->resolved($medium, [$pepperoni->id]), $this->resolved($medium, [$cheese->id])];

        $preview = $this->postJson('/api/recipes/base-split', ['product_id' => $product->id])->assertOk();
        $preview->assertJsonPath('variants.0.applicable', true)->assertJsonPath('variants.1.applicable', false);
        $this->assertDatabaseCount('recipes', 2);
        $this->postJson('/api/recipes/base-split', ['product_id' => $product->id, 'apply' => true, 'revision' => 'stale'])->assertStatus(409);

        $this->postJson('/api/recipes/base-split', ['product_id' => $product->id, 'apply' => true, 'revision' => $preview->json('revision')])->assertOk()->assertJsonPath('applied', 1);
        $this->assertEquals($before, [$this->resolved($medium, [$pepperoni->id]), $this->resolved($medium, [$cheese->id])]);
        $base = Recipe::where('product_variant_id', $medium->id)->whereNull('product_flavor_id')->firstOrFail();
        $this->assertTrue($base->applies_to_flavors);
        $this->assertEqualsCanonicalizing([$dough->id => 200.0, $mozzarella->id => 100.0], $base->items->mapWithKeys(fn ($i) => [$i->ingredient_id => (float) $i->quantity])->all());
        $this->postJson('/api/recipes/base-split', ['product_id' => $product->id])->assertOk()->assertJsonPath('variants.0.applicable', false);
    }
}
