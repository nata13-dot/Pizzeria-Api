<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComboAllowedOption extends Model
{
    protected $fillable = ['combo_item_id', 'product_flavor_id', 'modifier_id'];

    public function flavor()
    {
        return $this->belongsTo(ProductFlavor::class, 'product_flavor_id');
    }

    public function modifier()
    {
        return $this->belongsTo(Modifier::class);
    }
}
