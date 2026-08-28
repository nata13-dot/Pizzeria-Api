<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = ['branch_id', 'ingredient_id', 'inventory_batch_id', 'user_id', 'type', 'quantity', 'quantity_before', 'quantity_after', 'reference_type', 'reference_id', 'reason', 'comment'];

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
