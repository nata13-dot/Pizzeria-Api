<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;class Combo extends Model{protected $fillable=['branch_id','name','price','active'];public function items(){return $this->hasMany(ComboItem::class);}}
