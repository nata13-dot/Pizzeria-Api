<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['name', 'code', 'address', 'phone', 'timezone', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
