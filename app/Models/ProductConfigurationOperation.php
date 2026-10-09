<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductConfigurationOperation extends Model
{
    protected $fillable = ['branch_id', 'user_id', 'product_id', 'operation_key', 'request_hash', 'applied_revision'];

    protected $hidden = ['request_hash', 'user_id', 'branch_id'];
}
