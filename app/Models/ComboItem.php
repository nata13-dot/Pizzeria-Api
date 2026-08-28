<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComboItem extends Model
{
    protected $fillable = ['combo_id', 'product_variant_id', 'quantity', 'flavor_required', 'active'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'flavor_required' => 'boolean', 'active' => 'boolean'];
    }

    public function combo()
    {
        return $this->belongsTo(Combo::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function options()
    {
        return $this->hasMany(ComboAllowedOption::class);
    }

    public function allowedFlavors()
    {
        return $this->belongsToMany(ProductFlavor::class, 'combo_allowed_options')
            ->wherePivotNotNull('product_flavor_id');
    }

    public function allowedModifiers()
    {
        return $this->belongsToMany(Modifier::class, 'combo_allowed_options')
            ->wherePivotNotNull('modifier_id');
    }
}
