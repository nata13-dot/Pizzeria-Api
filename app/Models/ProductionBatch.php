<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ProductionBatch extends Model {protected $fillable=['branch_id','production_recipe_id','user_id','multiplier','produced_at','expires_at','notes'];protected function casts():array{return ['produced_at'=>'datetime','expires_at'=>'date','multiplier'=>'decimal:4'];}public function recipe(){return $this->belongsTo(ProductionRecipe::class,'production_recipe_id');}public function outputs(){return $this->hasMany(ProductionBatchOutput::class);}}
