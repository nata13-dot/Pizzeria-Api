<?php

namespace App\Models;

use App\Services\BranchClock;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = ['branch_id', 'ingredient_type_id', 'base_unit_id', 'name', 'sku', 'minimum_stock', 'critical_stock', 'shelf_life_days', 'expiry_alert_days', 'active'];

    protected $appends = ['current_stock'];

    protected function casts(): array
    {
        return ['minimum_stock' => 'decimal:4', 'critical_stock' => 'decimal:4', 'active' => 'boolean'];
    }

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function type()
    {
        return $this->belongsTo(IngredientType::class, 'ingredient_type_id');
    }

    public function presentations()
    {
        return $this->hasMany(IngredientPresentation::class);
    }

    public function batches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function getCurrentStockAttribute(): string
    {
        $today = app(BranchClock::class)->today($this->branch_id)->toDateString();

        return number_format((float) $this->batches()->where(function ($query) use ($today) {
            $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
        })->sum('available_quantity'), 4, '.', '');
    }
}
