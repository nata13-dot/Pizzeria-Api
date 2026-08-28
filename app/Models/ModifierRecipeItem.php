<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierRecipeItem extends Model
{
    protected $fillable = ['modifier_id', 'ingredient_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
