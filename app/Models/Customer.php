<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['branch_id', 'name', 'phone', 'email', 'birth_date', 'notes', 'active'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'active' => 'boolean'];
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function loyaltyRedemptions()
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    public function getPointsBalanceAttribute($value = null): float
    {
        return $value !== null
            ? (float) $value
            : (float) $this->loyaltyTransactions()->sum('points');
    }
}
