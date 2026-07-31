<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientPresentation;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
 public function index(Request $r){return Ingredient::with(['baseUnit','type','presentations.equivalentUnit'])->where('branch_id',$r->user()->branch_id)->orderBy('name')->paginate($r->integer('per_page',25));}
 public function store(Request $r){$data=$this->data($r);$data['branch_id']=$r->user()->branch_id;return response()->json(Ingredient::create($data)->load(['baseUnit','type']),201);}
 public function show(Request $r,Ingredient $ingredient){$this->own($r,$ingredient);return $ingredient->load(['baseUnit','type','presentations.equivalentUnit','batches']);}
 public function update(Request $r,Ingredient $ingredient){$this->own($r,$ingredient);$ingredient->update($this->data($r,true));return $ingredient->load(['baseUnit','type']);}
 public function destroy(Request $r,Ingredient $ingredient){$this->own($r,$ingredient);$ingredient->update(['active'=>false]);return response()->noContent();}
 public function presentation(Request $r,Ingredient $ingredient){$this->own($r,$ingredient);$data=$r->validate(['name'=>'required|string|max:100','quantity'=>'required|numeric|gt:0','equivalent_unit_id'=>'required|exists:units,id','supplier_sku'=>'nullable|string|max:100']);$equivalent=Unit::findOrFail($data['equivalent_unit_id']);$base=$ingredient->baseUnit;if($equivalent->dimension!==$base->dimension)throw ValidationException::withMessages(['equivalent_unit_id'=>'La unidad no es compatible con la unidad base.']);$data['base_quantity']=$data['quantity']*((float)$equivalent->base_factor/(float)$base->base_factor);return response()->json($ingredient->presentations()->create($data),201);}
 private function data(Request $r,bool $partial=false){$s=$partial?'sometimes|':'';return $r->validate(['ingredient_type_id'=>'nullable|exists:ingredient_types,id','base_unit_id'=>$s.'required|exists:units,id','name'=>$s.'required|string|max:150','sku'=>'nullable|string|max:100','minimum_stock'=>'sometimes|numeric|min:0','critical_stock'=>'sometimes|numeric|min:0','shelf_life_days'=>'nullable|integer|min:0','expiry_alert_days'=>'sometimes|integer|min:0','active'=>'sometimes|boolean']);}
 private function own(Request $r,Ingredient $i):void{abort_unless($i->branch_id===$r->user()->branch_id,404);}
}
