<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryBatch extends Model
{
    protected $fillable = ['branch_id', 'ingredient_id', 'purchase_item_id', 'lot_code', 'received_at', 'expires_at', 'initial_quantity', 'available_quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'expires_at' => 'date', 'initial_quantity' => 'decimal:4', 'available_quantity' => 'decimal:4', 'unit_cost' => 'decimal:6'];
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
