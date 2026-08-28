<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeItem extends Model
{
    protected $fillable = ['recipe_id', 'ingredient_id', 'quantity', 'component'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
