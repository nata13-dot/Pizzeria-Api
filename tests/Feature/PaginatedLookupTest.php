<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaginatedLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $admin = User::where('email', 'admin@pizzeria.local')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_customers_can_be_paged_and_searched_beyond_the_first_page_without_crossing_branches(): void
    {
        $admin = $this->admin();
        Customer::query()->where('branch_id', $admin->branch_id)->delete();
        for ($i = 1; $i <= 56; $i++) {
            Customer::create(['branch_id' => $admin->branch_id, 'name' => sprintf('Cliente %03d', $i), 'phone' => '555'.sprintf('%07d', $i), 'active' => true]);
        }
        $other = Branch::create(['name' => 'Otra', 'code' => 'OTHER-LOOKUP']);
        Customer::create(['branch_id' => $other->id, 'name' => 'Cliente 056 ajeno', 'phone' => '5559999999', 'active' => true]);
        Customer::create(['branch_id' => $admin->branch_id, 'name' => 'Cliente 056 inactivo', 'phone' => '5558888888', 'active' => false]);
        $this->getJson('/api/customers?active=1')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 56)->assertJsonPath('last_page', 3);
        $this->getJson('/api/customers?active=1&page=2')->assertOk()->assertJsonPath('data.0.name', 'Cliente 026');
        $this->getJson('/api/customers?active=1&page=3')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('to', 56);
        $this->getJson('/api/customers?active=1&search=056')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Cliente 056');
        $this->getJson('/api/customers?active=1&search=5550000056')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/customers?search=Inexistente')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_ingredient_search_reaches_beyond_one_hundred_and_preserves_pagination_and_scope(): void
    {
        $admin = $this->admin();
        $unit = Unit::firstOrFail();
        for ($i = 1; $i <= 105; $i++) {
            Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => sprintf('Prueba %03d', $i), 'sku' => sprintf('LOOKUP-%03d', $i), 'active' => true]);
        }
        $other = Branch::create(['name' => 'Otra', 'code' => 'OTHER-INGREDIENT']);
        Ingredient::create(['branch_id' => $other->id, 'base_unit_id' => $unit->id, 'name' => 'Prueba 105 ajena', 'sku' => 'LOOKUP-105-OTHER', 'active' => true]);
        Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Prueba 105 inactiva', 'active' => false]);
        $this->getJson('/api/ingredients?active=1&search=Prueba&per_page=25')->assertOk()->assertJsonPath('total', 105)->assertJsonPath('last_page', 5)->assertJsonCount(25, 'data');
        $this->getJson('/api/ingredients?active=1&search=Prueba&page=2')->assertOk()->assertJsonPath('data.0.name', 'Prueba 026');
        $this->getJson('/api/ingredients?active=1&search=Prueba&page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('to', 105);
        $this->getJson('/api/ingredients?active=1&search=105')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'LOOKUP-105');
        $this->getJson('/api/ingredients?active=1&search=LOOKUP-105')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/ingredients?search=Inexistente')->assertOk()->assertJsonPath('total', 0);
        Ingredient::create(['branch_id' => $admin->branch_id, 'base_unit_id' => $unit->id, 'name' => 'Sin coincidencia numerica', 'active' => true]);
        $expectedZero = Ingredient::where('branch_id', $admin->branch_id)->where('active', true)->where(fn ($q) => $q->where('name', 'like', '%0%')->orWhere('sku', 'like', '%0%'))->count();
        $this->getJson('/api/ingredients?active=1&search=0')->assertOk()->assertJsonPath('total', $expectedZero);
        $this->getJson('/api/ingredients?per_page=0')->assertUnprocessable();
        $this->getJson('/api/ingredients?per_page=101')->assertUnprocessable();
        $this->getJson('/api/ingredients?page=-1')->assertUnprocessable();
    }

    public function test_users_have_stable_non_overlapping_pages_and_are_branch_scoped(): void
    {
        $admin = $this->admin();
        $original = User::where('branch_id', $admin->branch_id)->count();
        for ($i = 1; $i <= 61; $i++) {
            User::factory()->create(['branch_id' => $admin->branch_id, 'role_id' => $admin->role_id]);
        }
        $other = Branch::create(['name' => 'Otra', 'code' => 'OTHER-USER']);
        $foreign = User::factory()->create(['branch_id' => $other->id, 'role_id' => $admin->role_id]);
        $first = $this->getJson('/api/users')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('total', $original + 61);
        $second = $this->getJson('/api/users?page=2')->assertOk()->assertJsonCount(30, 'data');
        $last = $this->getJson('/api/users?page='.$first->json('last_page'))->assertOk();
        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($last->json('data'), 'id'));
        $this->assertCount(count($ids), array_unique($ids));
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame($original + 61, $last->json('to'));
    }
}
