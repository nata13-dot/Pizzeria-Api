<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $fillable = ['purchase_id', 'ingredient_id', 'ingredient_presentation_id', 'presentations_quantity', 'base_quantity', 'total_cost', 'base_unit_cost', 'expires_at', 'lot_code'];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
