<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modifier extends Model
{
    protected $fillable = ['branch_id', 'name', 'type', 'price', 'active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'active' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }

    public function variantRules()
    {
        return $this->hasMany(ProductModifierRule::class);
    }
}
