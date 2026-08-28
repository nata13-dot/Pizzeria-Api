<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemComponent extends Model
{
    protected $fillable = [
        'order_item_id',
        'combo_item_id',
        'product_variant_id',
        'name',
        'quantity',
        'flavors',
        'modifiers',
        'notes',
    ];

    protected function casts(): array
    {
        return ['flavors' => 'array', 'modifiers' => 'array'];
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
