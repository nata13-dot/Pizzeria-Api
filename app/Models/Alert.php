<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Alert extends Model { protected $fillable=['branch_id','ingredient_id','inventory_batch_id','type','severity','message','resolved_at']; protected function casts():array{return ['resolved_at'=>'datetime'];} public function ingredient(){return $this->belongsTo(Ingredient::class);} public function batch(){return $this->belongsTo(InventoryBatch::class,'inventory_batch_id');} }
