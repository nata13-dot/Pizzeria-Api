<?php
namespace App\Services;
use App\Models\ProductVariant;use App\Models\Recipe;use Illuminate\Validation\ValidationException;
class RecipeResolver{
 public function resolve(ProductVariant $variant,array $flavorIds=[],array $modifierIds=[]):array{
  $variant->loadMissing('product');$flavorIds=array_values(array_unique($flavorIds));if(count($flavorIds)>$variant->max_flavors)throw ValidationException::withMessages(['flavors'=>'Se excedió el máximo de sabores.']);
  if(count($flavorIds)>1&&$variant->product->type==='pizza'&&!$variant->allows_half_and_half)throw ValidationException::withMessages(['flavors'=>'Esta variante no permite mitad y mitad.']);
  $recipes=Recipe::with('items')->where('product_variant_id',$variant->id)->when($flavorIds,fn($q)=>$q->whereIn('product_flavor_id',$flavorIds))->get();if($recipes->isEmpty())throw ValidationException::withMessages(['recipe'=>'No existe receta para la selección.']);
  $totals=[];$add=function($item,float $factor=1)use(&$totals){$totals[$item->ingredient_id]=($totals[$item->ingredient_id]??0)+(float)$item->quantity*$factor;};
  if(count($recipes)===1)$recipes->first()->items->each(fn($i)=>$add($i));
  elseif($variant->product->type==='pizza'){foreach($recipes->first()->items->whereIn('component',['base','sauce','packaging']) as $i)$add($i);foreach($recipes as $recipe)foreach($recipe->items->whereIn('component',['topping','other']) as $i)$add($i,1/count($recipes));}
  else {foreach($recipes->first()->items->whereNotIn('component',['sauce']) as $i)$add($i);foreach($recipes as $recipe)foreach($recipe->items->where('component','sauce') as $i)$add($i,1/count($recipes));}
  $rules=$variant->modifierRules()->with('modifier.items')->whereIn('modifier_id',$modifierIds)->get();if($rules->count()!==count(array_unique($modifierIds))||$rules->contains(fn($r)=>!$r->allowed))throw ValidationException::withMessages(['modifiers'=>'Existe un modificador incompatible.']);foreach($rules as $rule)foreach($rule->modifier->items as $i)$add($i);
  return collect($totals)->map(fn($q,$id)=>['ingredient_id'=>(int)$id,'quantity'=>round($q,4)])->values()->all();
 }
}
