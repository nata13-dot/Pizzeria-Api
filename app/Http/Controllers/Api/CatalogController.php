<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\IngredientType;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    private function model(string $catalog): string { return match($catalog){'units'=>Unit::class,'ingredient-types'=>IngredientType::class,'suppliers'=>Supplier::class,default=>abort(404)}; }
    public function index(string $catalog){$model=$this->model($catalog);return $model::orderBy('name')->get();}
    public function store(Request $request,string $catalog){$data=$this->validateData($request,$catalog);if($catalog==='suppliers')$data['branch_id']=$request->user()->branch_id;return response()->json($this->model($catalog)::create($data),201);}
    public function update(Request $request,string $catalog,int $id){$model=$this->model($catalog)::findOrFail($id);$model->update($this->validateData($request,$catalog,true));return $model;}
    public function destroy(string $catalog,int $id){$model=$this->model($catalog)::findOrFail($id);$model->update(['active'=>false]);return response()->noContent();}
    private function validateData(Request $r,string $catalog,bool $partial=false):array{$sometimes=$partial?'sometimes|':'';return match($catalog){
      'units'=>$r->validate(['name'=>$sometimes.'required|string|max:100','symbol'=>$sometimes.'required|string|max:20','dimension'=>$sometimes.'required|in:mass,volume,count','base_factor'=>$sometimes.'required|numeric|gt:0','active'=>'sometimes|boolean']),
      'ingredient-types'=>$r->validate(['name'=>$sometimes.'required|string|max:100','suggested_shelf_life_days'=>'nullable|integer|min:0','expiry_alert_days'=>$sometimes.'required|integer|min:0','active'=>'sometimes|boolean']),
      'suppliers'=>$r->validate(['name'=>$sometimes.'required|string|max:150','phone'=>'nullable|string|max:30','address'=>'nullable|string','notes'=>'nullable|string','active'=>'sometimes|boolean'])};}
}
