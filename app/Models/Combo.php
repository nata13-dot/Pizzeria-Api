<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Combo extends Model
{
    protected $fillable = ['branch_id', 'name', 'price', 'image_data_uri', 'active', 'available_days'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'active' => 'boolean', 'available_days' => 'array'];
    }

    /** Whether the combo is sold on the given day of the week (0 = Sunday). */
    public function availableOn(int $dayOfWeek): bool
    {
        return empty($this->available_days) || in_array($dayOfWeek, array_map('intval', $this->available_days), true);
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
