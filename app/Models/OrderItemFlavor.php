<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemFlavor extends Model
{
    protected $fillable = ['order_item_id', 'product_flavor_id', 'ratio'];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function flavor()
    {
        return $this->belongsTo(ProductFlavor::class, 'product_flavor_id');
    }
}
