<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class IngredientType extends Model { protected $fillable=['name','suggested_shelf_life_days','expiry_alert_days','active']; protected function casts(): array{return ['active'=>'boolean'];} }
