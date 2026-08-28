<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_zone_fee_is_calculated_by_server_and_required_when_configured(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        Setting::create([
            'branch_id' => $admin->branch_id,
            'key' => 'delivery_zones',
            'value' => [
                ['name' => 'Centro', 'fee' => 30, 'active' => true],
                ['name' => 'Lejana', 'fee' => 80, 'active' => false],
            ],
        ]);
        $variant = $this->variant($admin);
        $payload = [
            'status' => 'confirmed',
            'type' => 'delivery',
            'delivery_fee' => 1,
            'delivery' => [
                'recipient' => 'Cliente',
                'phone' => '5551234567',
                'address' => 'Calle Uno',
                'delivery_zone' => 'Centro',
            ],
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 230]],
        ];

        $this->postJson('/api/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('delivery_fee', 30)
            ->assertJsonPath('total', 230)
            ->assertJsonPath('delivery.delivery_zone', 'Centro');

        $payload['delivery']['delivery_zone'] = 'Lejana';
        $this->postJson('/api/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delivery.delivery_zone');
    }

    public function test_non_delivery_order_cannot_add_an_arbitrary_delivery_fee(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);
        $variant = $this->variant($admin);

        $this->postJson('/api/orders', [
            'status' => 'confirmed',
            'type' => 'pickup',
            'delivery_fee' => 500,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertCreated()
            ->assertJsonPath('delivery_fee', 0)
            ->assertJsonPath('total', 200);
    }

    private function variant(User $user): ProductVariant
    {
        $grams = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $user->branch_id, 'base_unit_id' => $grams->id, 'name' => 'Insumo entrega']);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Producto entrega', 'type' => 'other']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Única', 'price' => 200]);
        $recipe = Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Receta entrega']);
        $recipe->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'component' => 'base']);

        return $variant;
    }
}
