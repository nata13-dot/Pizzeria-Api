<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = ['product_variant_id', 'product_flavor_id', 'applies_to_flavors', 'base_only', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'applies_to_flavors' => 'boolean', 'base_only' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function flavor()
    {
        return $this->belongsTo(ProductFlavor::class, 'product_flavor_id');
    }
}
