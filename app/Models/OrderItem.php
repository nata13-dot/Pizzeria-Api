<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_variant_id', 'combo_id', 'name', 'quantity', 'unit_price', 'total', 'notes'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function flavors()
    {
        return $this->hasMany(OrderItemFlavor::class);
    }

    public function modifiers()
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function ingredients()
    {
        return $this->hasMany(OrderItemIngredient::class);
    }

    public function components()
    {
        return $this->hasMany(OrderItemComponent::class);
    }
}
