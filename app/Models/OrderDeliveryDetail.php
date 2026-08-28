<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDeliveryDetail extends Model
{
    protected $fillable = ['order_id', 'recipient', 'phone', 'address', 'references', 'map_url', 'delivery_zone', 'payment_received'];

    protected function casts(): array
    {
        return ['payment_received' => 'boolean'];
    }
}
