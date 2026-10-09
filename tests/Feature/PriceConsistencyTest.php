<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Combo;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\BranchClock;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function sale(): array
    {
        $this->seed();
        $user = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($user);
        $product = Product::create(['branch_id' => $user->branch_id, 'name' => 'Decimales', 'type' => 'beverage']);
        $variant = $product->variants()->create(['name' => 'Única', 'price' => '.10']);
        Recipe::create(['product_variant_id' => $variant->id, 'name' => 'Base']);

        return [$variant, ['status' => 'confirmed', 'type' => 'pickup', 'contact_name' => 'Ana', 'contact_phone' => '123',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => '.30']],
            'pricing_expectation' => ['items' => [['unit_price' => '.10', 'total' => '.30']], 'delivery_fee' => 0, 'total' => '.30'],
        ]];
    }

    public function test_price_conflict_rolls_back_before_payment_and_same_key_can_retry_current_price(): void
    {
        [$variant, $payload] = $this->sale();
        $variant->update(['price' => '.20']);
        $auditCount = AuditLog::count();
        $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'price-change-test'])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('pricing.items.0.unit_price', fn ($value) => (float) $value === .20);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertSame($auditCount, AuditLog::count());
        $payload['pricing_expectation'] = ['items' => [['unit_price' => '.20', 'total' => '.60']], 'delivery_fee' => 0, 'total' => '.60'];
        $payload['payments'][0]['amount'] = '.60';
        $id = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'price-change-test'])->assertCreated()->json('id');
        $variant->update(['price' => 900, 'active' => false]);
        $payload['scheduled_at'] = '2000-01-01';
        $this->postJson('/api/orders', $payload, ['Idempotency-Key' => 'price-change-test'])->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_money_rounding_and_range_are_explicit(): void
    {
        $this->assertSame('0.30', Money::sum(['0.10', '0.20']));
        $this->assertSame('1.01', Money::normalize('1.005'));
        $this->assertSame('3.03', Money::multiply('1.005', '3'));
        $this->assertSame('0.03', Money::multiply('0.05', '0.5'));
        $this->assertSame('25.00', Money::total('25.55', '2.05', '1.50'));
        $this->assertSame('9999999999.99', Money::normalize('9999999999.99'));
        $this->expectException(ValidationException::class);
        Money::multiply('9999999999.99', '2');
    }

    public function test_client_prices_and_unconfigured_delivery_fee_are_not_authoritative(): void
    {
        [$variant, $payload] = $this->sale();
        unset($payload['pricing_expectation']);
        $payload['type'] = 'delivery';
        $payload['delivery'] = ['recipient' => 'Ana', 'phone' => '123', 'address' => 'Centro'];
        $payload['delivery_fee'] = 500;
        $payload['unit_price'] = 0;
        $payload['subtotal'] = 0;
        $payload['total'] = 0;
        $payload['items'][0]['unit_price'] = 0;
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('total', .3)->assertJsonPath('delivery_fee', 0);
        Sanctum::actingAs(User::where('email', 'cajero@pizzeria.local')->firstOrFail());
        $payload['discount'] = .1;
        $this->postJson('/api/orders', $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_expectations_for_each_boundary_are_checked_and_courtesy_keeps_its_total_without_payment(): void
    {
        [$variant, $payload] = $this->sale();
        foreach (['unit_price', 'line_total', 'delivery_fee', 'total'] as $field) {
            $bad = $payload;
            if ($field === 'unit_price' || $field === 'line_total') {
                $bad['pricing_expectation']['items'][0][$field === 'line_total' ? 'total' : $field] = 1;
            } else {
                $bad['pricing_expectation'][$field] = 1;
            }
            $this->postJson('/api/orders', $bad)->assertStatus(409)->assertJsonPath('code', 'price_changed');
        }
        $this->assertDatabaseCount('orders', 0);
        $malformed = $payload;
        $malformed['pricing_expectation'] = [];
        $this->postJson('/api/orders', $malformed)->assertUnprocessable();
        $payload['courtesy'] = true;
        $payload['payments'] = [];
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('total', .3)->assertJsonPath('courtesy', true);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_combo_units_extras_discount_and_delivery_match_the_frontend_examples(): void
    {
        [$variant, $payload] = $this->sale();
        $product = $variant->product;
        $product->update(['type' => 'pizza']);
        $variant->update(['price' => '1.20', 'max_flavors' => 2, 'allows_half_and_half' => true]);
        $flavors = [];
        foreach (['Uno', 'Dos'] as $name) {
            $flavor = $product->flavors()->create(['name' => $name]);
            $flavors[] = $flavor->id;
            Recipe::create(['product_variant_id' => $variant->id, 'product_flavor_id' => $flavor->id, 'name' => $name]);
        }
        $modifiers = [];
        foreach ([['10.00', '.15'], ['.20', null]] as $index => [$price, $override]) {
            $modifier = Modifier::create(['branch_id' => $product->branch_id, 'name' => 'Extra '.$index, 'type' => 'instruction', 'price' => $price]);
            $variant->modifierRules()->create(['modifier_id' => $modifier->id, 'allowed' => true, 'price_override' => $override]);
            $modifiers[] = $modifier->id;
        }
        Setting::updateOrCreate(['branch_id' => $product->branch_id, 'key' => 'half_and_half_extra'], ['value' => '.10']);
        Setting::updateOrCreate(['branch_id' => $product->branch_id, 'key' => 'delivery_zones'], ['value' => [['name' => 'Centro', 'fee' => '.30', 'active' => true]]]);
        $combo = Combo::create(['branch_id' => $product->branch_id, 'name' => 'Doble', 'price' => '2.00']);
        $item = $combo->items()->create(['product_variant_id' => $variant->id, 'quantity' => 2, 'flavor_required' => true]);
        $payload['type'] = 'delivery';
        $payload['delivery'] = ['recipient' => 'Ana', 'phone' => '123', 'address' => 'Centro', 'delivery_zone' => 'Centro'];
        $payload['discount'] = '.10';
        $payload['items'] = [['combo_id' => $combo->id, 'quantity' => 3, 'components' => [['combo_item_id' => $item->id, 'flavor_ids' => $flavors, 'modifier_ids' => $modifiers]]]];
        $payload['payments'][0]['amount'] = '8.90';
        $payload['pricing_expectation'] = ['items' => [['unit_price' => '2.90', 'total' => '8.70']], 'delivery_fee' => '.30', 'total' => '8.90'];
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('total', 8.9);
    }

    public function test_purchase_total_sums_rounded_line_costs(): void
    {
        [$variant] = $this->sale();
        $unit = Unit::where('symbol', 'g')->firstOrFail();
        $ingredient = Ingredient::create(['branch_id' => $variant->product->branch_id, 'name' => 'Costo decimal', 'base_unit_id' => $unit->id]);
        $presentation = $this->postJson('/api/ingredients/'.$ingredient->id.'/presentations', ['name' => 'Unidad', 'quantity' => 1, 'equivalent_unit_id' => $unit->id])->assertCreated()->json();
        $line = ['ingredient_presentation_id' => $presentation['id'], 'presentations_quantity' => 1, 'total_cost' => '.005'];
        $this->postJson('/api/purchases', ['purchased_at' => app(BranchClock::class)->today($variant->product->branch_id)->toDateString(), 'payment_source' => 'owner', 'items' => [$line + ['lot_code' => 'A'], $line + ['lot_code' => 'B']]])->assertCreated()->assertJsonPath('total', '0.02')->assertJsonPath('items.0.total_cost', fn ($value) => (float) $value === 0.01);
    }
}
