<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\IngredientPresentation;
use App\Models\InventoryBatch;
use App\Models\Purchase;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
 public function index(Request $r){return Purchase::with(['supplier','items.ingredient'])->where('branch_id',$r->user()->branch_id)->latest('purchased_at')->paginate(25);}
 public function show(Request $r,Purchase $purchase){abort_unless($purchase->branch_id===$r->user()->branch_id,404);return $purchase->load(['supplier','items.ingredient']);}
 public function store(Request $r,InventoryService $inventory){$data=$r->validate(['supplier_id'=>'nullable|exists:suppliers,id','purchased_at'=>'required|date','payment_source'=>'required|in:cash,owner,bank,credit,other','notes'=>'nullable|string','items'=>'required|array|min:1','items.*.ingredient_presentation_id'=>'required|exists:ingredient_presentations,id','items.*.presentations_quantity'=>'required|numeric|gt:0','items.*.total_cost'=>'required|numeric|min:0','items.*.expires_at'=>'nullable|date','items.*.lot_code'=>'nullable|string|max:100']);
  $purchase=DB::transaction(function()use($r,$data,$inventory){$purchase=Purchase::create(['branch_id'=>$r->user()->branch_id,'supplier_id'=>$data['supplier_id']??null,'user_id'=>$r->user()->id,'purchased_at'=>$data['purchased_at'],'payment_source'=>$data['payment_source'],'notes'=>$data['notes']??null,'total'=>collect($data['items'])->sum('total_cost')]);foreach($data['items'] as $row){$presentation=IngredientPresentation::with('ingredient')->findOrFail($row['ingredient_presentation_id']);abort_unless($presentation->ingredient->branch_id===$r->user()->branch_id,422);$baseQty=(float)$presentation->base_quantity*(float)$row['presentations_quantity'];$item=$purchase->items()->create(['ingredient_id'=>$presentation->ingredient_id,'ingredient_presentation_id'=>$presentation->id,'presentations_quantity'=>$row['presentations_quantity'],'base_quantity'=>$baseQty,'total_cost'=>$row['total_cost'],'base_unit_cost'=>$baseQty?((float)$row['total_cost']/$baseQty):0,'expires_at'=>$row['expires_at']??null,'lot_code'=>$row['lot_code']??null]);$batch=InventoryBatch::create(['branch_id'=>$purchase->branch_id,'ingredient_id'=>$presentation->ingredient_id,'purchase_item_id'=>$item->id,'lot_code'=>$row['lot_code']??null,'received_at'=>$data['purchased_at'],'expires_at'=>$row['expires_at']??null,'initial_quantity'=>$baseQty,'available_quantity'=>0,'unit_cost'=>$item->base_unit_cost]);$inventory->move($batch,$baseQty,'purchase',$r->user(),$purchase);$inventory->refreshAlerts($presentation->ingredient);}return $purchase;});return response()->json($purchase->load(['supplier','items.ingredient']),201);}
}
