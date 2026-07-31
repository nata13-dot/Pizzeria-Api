<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ProductionRecipe extends Model {protected $fillable=['branch_id','name','yield_quantity','yield_unit_id','shelf_life_days','active'];protected function casts():array{return ['yield_quantity'=>'decimal:4','active'=>'boolean'];}public function items(){return $this->hasMany(ProductionRecipeItem::class);}public function yieldUnit(){return $this->belongsTo(Unit::class,'yield_unit_id');}}
