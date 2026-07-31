<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IngredientPresentation extends Model { protected $fillable=['ingredient_id','name','quantity','equivalent_unit_id','base_quantity','supplier_sku','active']; protected function casts():array{return ['quantity'=>'decimal:4','base_quantity'=>'decimal:4','active'=>'boolean'];} public function ingredient(){return $this->belongsTo(Ingredient::class);} public function equivalentUnit(){return $this->belongsTo(Unit::class,'equivalent_unit_id');} }
