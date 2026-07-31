<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ProductionRecipeItem extends Model {protected $fillable=['production_recipe_id','ingredient_id','quantity'];public function ingredient(){return $this->belongsTo(Ingredient::class);}}
