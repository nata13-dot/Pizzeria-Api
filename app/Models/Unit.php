<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Unit extends Model { protected $fillable=['name','symbol','dimension','base_factor','active']; protected function casts(): array{return ['base_factor'=>'decimal:6','active'=>'boolean'];} }
