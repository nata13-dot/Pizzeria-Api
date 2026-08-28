<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFlavor extends Model
{
    protected $fillable = ['product_id', 'name', 'ingredient_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }
}
