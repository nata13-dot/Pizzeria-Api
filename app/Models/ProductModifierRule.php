<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductModifierRule extends Model
{
    protected $fillable = ['product_variant_id', 'modifier_id', 'allowed', 'price_override'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean', 'price_override' => 'decimal:2'];
    }

    public function modifier()
    {
        return $this->belongsTo(Modifier::class);
    }
}
