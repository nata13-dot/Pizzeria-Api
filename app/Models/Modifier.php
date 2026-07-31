<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;class Modifier extends Model{protected $fillable=['branch_id','name','type','price','active'];public function items(){return $this->hasMany(ModifierRecipeItem::class);}}
