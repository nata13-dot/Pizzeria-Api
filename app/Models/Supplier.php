<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Supplier extends Model { protected $fillable=['branch_id','name','phone','address','notes','active']; protected function casts():array{return ['active'=>'boolean'];} }
