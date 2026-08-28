<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'customer_id', 'loyalty_rule_id', 'order_id', 'user_id', 'type', 'points',
        'remaining_points', 'expires_at', 'comment', 'reversal_of_transaction_id',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function rule()
    {
        return $this->belongsTo(LoyaltyRule::class, 'loyalty_rule_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_transaction_id');
    }
}
