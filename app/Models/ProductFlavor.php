<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;class ProductFlavor extends Model{protected $fillable=['product_id','name','ingredient_id','active'];public function product(){return $this->belongsTo(Product::class);}}
