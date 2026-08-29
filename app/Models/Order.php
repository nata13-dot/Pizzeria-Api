<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'branch_id', 'user_id', 'idempotency_key', 'customer_id', 'order_date', 'daily_number', 'status', 'type', 'sales_channel',
        'scheduled_at', 'pending_expires_at', 'subtotal', 'discount', 'delivery_fee', 'total',
        'courtesy', 'collect_on_delivery', 'inventory_deducted', 'stock_warnings', 'stock_shortage_authorized_by',
        'stock_shortage_authorized_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'scheduled_at' => 'datetime',
            'pending_expires_at' => 'datetime',
            'courtesy' => 'boolean',
            'collect_on_delivery' => 'boolean',
            'inventory_deducted' => 'boolean',
            'stock_warnings' => 'array',
            'stock_shortage_authorized_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shortageAuthorizer()
    {
        return $this->belongsTo(User::class, 'stock_shortage_authorized_by');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function delivery()
    {
        return $this->hasOne(OrderDeliveryDetail::class);
    }

    public function histories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function reservations()
    {
        return $this->hasMany(StockReservation::class);
    }

    public function loyaltyRedemptions()
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }
}
