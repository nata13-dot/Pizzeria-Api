<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\InventoryBatch;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_converts_presentations_and_creates_inventory(): void
    {
        $this->seed(); $user=User::where('email','admin@pizzeria.local')->firstOrFail(); Sanctum::actingAs($user);
        $grams=Unit::where('symbol','g')->firstOrFail(); $kg=Unit::where('symbol','kg')->firstOrFail();
        $ingredient=$this->postJson('/api/ingredients',['name'=>'Queso mozzarella','base_unit_id'=>$grams->id,'minimum_stock'=>2000,'critical_stock'=>500])->assertCreated()->json();
        $presentation=$this->postJson("/api/ingredients/{$ingredient['id']}/presentations",['name'=>'Bolsa 2 kg','quantity'=>2,'equivalent_unit_id'=>$kg->id])->assertCreated()->assertJsonPath('base_quantity','2000.0000')->json();
        $this->postJson('/api/purchases',['purchased_at'=>today()->toDateString(),'payment_source'=>'owner','items'=>[['ingredient_presentation_id'=>$presentation['id'],'presentations_quantity'=>2,'total_cost'=>400,'expires_at'=>today()->addDays(5)->toDateString(),'lot_code'=>'Q-01']]])->assertCreated()->assertJsonPath('total','400.00');
        $this->assertDatabaseHas('inventory_batches',['ingredient_id'=>$ingredient['id'],'available_quantity'=>4000]);
        $this->assertDatabaseHas('inventory_movements',['ingredient_id'=>$ingredient['id'],'type'=>'purchase','quantity'=>4000]);
    }

    public function test_fefo_uses_earliest_expiry_without_partial_changes_on_shortage(): void
    {
        $this->seed(); $user=User::firstOrFail(); $unit=Unit::where('symbol','g')->firstOrFail();
        $ingredient=Ingredient::create(['branch_id'=>$user->branch_id,'base_unit_id'=>$unit->id,'name'=>'Pepperoni','minimum_stock'=>100,'critical_stock'=>20]);
        $later=InventoryBatch::create(['branch_id'=>$user->branch_id,'ingredient_id'=>$ingredient->id,'received_at'=>today(),'expires_at'=>today()->addDays(10),'initial_quantity'=>100,'available_quantity'=>100]);
        $first=InventoryBatch::create(['branch_id'=>$user->branch_id,'ingredient_id'=>$ingredient->id,'received_at'=>today(),'expires_at'=>today()->addDays(2),'initial_quantity'=>80,'available_quantity'=>80]);
        $service=app(InventoryService::class); $result=$service->consumeFefo($ingredient,120,'sale',$user);
        $this->assertSame(120.0,$result['consumed']); $this->assertEquals(0,$first->fresh()->available_quantity); $this->assertEquals(60,$later->fresh()->available_quantity);
        $short=$service->consumeFefo($ingredient,100,'sale',$user); $this->assertSame(40.0,$short['shortage']); $this->assertEquals(60,$later->fresh()->available_quantity);
    }
}
