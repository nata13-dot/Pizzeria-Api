<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Combo extends Model
{
    protected $fillable = ['branch_id', 'name', 'price', 'active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'active' => 'boolean'];
    }

    /** Components available when creating a new order. */
    public function items()
    {
        return $this->hasMany(ComboItem::class)->where('active', true);
    }

    /** Includes deactivated components so administrators can inspect history. */
    public function allItems()
    {
        return $this->hasMany(ComboItem::class);
    }
}
