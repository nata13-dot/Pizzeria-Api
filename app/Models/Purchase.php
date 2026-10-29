<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = ['branch_id', 'supplier_id', 'user_id', 'idempotency_key', 'purchased_at', 'payment_source', 'total', 'receipt_path', 'notes'];

    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'total' => 'decimal:2'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
