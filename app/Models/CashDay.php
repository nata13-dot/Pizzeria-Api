<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDay extends Model
{
    protected $fillable = [
        'branch_id',
        'date',
        'opened_by',
        'closed_by',
        'opening_amount',
        'expected_amount',
        'actual_amount',
        'difference',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'opening_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
