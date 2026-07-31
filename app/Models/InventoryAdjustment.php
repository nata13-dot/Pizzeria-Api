<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InventoryAdjustment extends Model { protected $fillable=['branch_id','ingredient_id','inventory_batch_id','user_id','quantity','reason','comment']; }
