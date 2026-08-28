<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionBatchOutput extends Model
{
    protected $fillable = ['production_batch_id', 'ingredient_id', 'inventory_batch_id', 'quantity', 'portion_name', 'grams_per_portion'];

    public function inventoryBatch()
    {
        return $this->belongsTo(InventoryBatch::class);
    }
}
