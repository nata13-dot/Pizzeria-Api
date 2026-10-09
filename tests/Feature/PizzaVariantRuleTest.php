<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PizzaVariantRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_catalog_rejects_stuffed_crust_for_square_and_neapolitan_variants(): void
    {
        foreach (['Cuadrada 35x25 cm', 'Napolitana 35 cm'] as $name) {
            $this->postJson('/api/products', [
                'name' => 'Pizza '.$name,
                'type' => 'pizza',
                'variants' => [[
                    'name' => $name,
                    'price' => 155,
                    'max_flavors' => 2,
                    'allows_stuffed_crust' => true,
                ]],
            ])->assertUnprocessable()->assertJsonValidationErrors('variants.0.allows_stuffed_crust');
        }

        $this->postJson('/api/products', [
            'name' => 'Pizza Grande',
            'type' => 'pizza',
            'variants' => [['name' => 'Grande 40 cm', 'price' => 180, 'allows_stuffed_crust' => true]],
        ])->assertCreated();
    }

    public function test_order_rejects_a_stale_stuffed_crust_rule_for_a_square_variant(): void
    {
        [$variant, $modifier] = $this->staleStuffedCrustVariant('Cuadrada 35x25 cm');

        $this->postJson('/api/orders', [
            'status' => 'draft',
            'type' => 'pickup',
            'contact_name' => 'Cliente local',
            'contact_phone' => '5551234567',
            'items' => [[
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'modifier_ids' => [$modifier->id],
            ]],
            'payments' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_combo_rejects_a_stale_stuffed_crust_rule_for_a_neapolitan_variant(): void
    {
        [$variant, $modifier] = $this->staleStuffedCrustVariant('Napolitana 35 cm');
        $combo = Combo::create(['branch_id' => $this->user->branch_id, 'name' => 'Combo napolitano', 'price' => 200]);
        $item = $combo->items()->create(['product_variant_id' => $variant->id, 'quantity' => 1]);
        $item->options()->create(['modifier_id' => $modifier->id]);

        $this->postJson('/api/orders', [
            'status' => 'draft',
            'type' => 'pickup',
            'contact_name' => 'Cliente local',
            'contact_phone' => '5551234567',
            'items' => [[
                'combo_id' => $combo->id,
                'quantity' => 1,
                'components' => [[
                    'combo_item_id' => $item->id,
                    'modifier_ids' => [$modifier->id],
                ]],
            ]],
            'payments' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_pos_catalog_lists_only_flavors_with_an_active_recipe_for_each_variant(): void
    {
        $product = Product::create(['branch_id' => $this->user->branch_id, 'name' => 'Pizza por receta', 'type' => 'pizza']);
        $variant = $product->variants()->create(['name' => 'Grande', 'price' => 180, 'max_flavors' => 2]);
        $available = $product->flavors()->create(['name' => 'Ranchera']);
        $unavailable = $product->flavors()->create(['name' => 'Sin receta']);
        Recipe::create(['product_variant_id' => $variant->id, 'product_flavor_id' => $available->id, 'name' => 'Grande Ranchera']);
        Recipe::create(['product_variant_id' => $variant->id, 'product_flavor_id' => $unavailable->id, 'name' => 'Inactiva', 'active' => false]);

        $catalogVariant = collect($this->getJson('/api/pos/catalog')->assertOk()->json())
            ->firstWhere('id', $product->id)['variants'][0];

        $this->assertSame([$available->id], $catalogVariant['available_flavor_ids']);
        $this->assertFalse($catalogVariant['has_base_recipe']);
        $this->assertArrayNotHasKey('recipes', $catalogVariant);
    }

    private function staleStuffedCrustVariant(string $name): array
    {
        $product = Product::create(['branch_id' => $this->user->branch_id, 'name' => 'Pizza '.$name, 'type' => 'pizza']);
        $variant = $product->variants()->create(['name' => $name, 'price' => 155, 'allows_stuffed_crust' => true]);
        $modifier = Modifier::create([
            'branch_id' => $this->user->branch_id,
            'name' => 'Orilla rellena de queso con chipotle',
            'type' => 'stuffed_crust',
            'price' => 20,
        ]);
        $variant->modifierRules()->create(['modifier_id' => $modifier->id, 'allowed' => true]);

        return [$variant, $modifier];
    }
}
