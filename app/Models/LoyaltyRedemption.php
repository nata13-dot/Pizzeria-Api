<?php namespace App\Models;use Illuminate\Database\Eloquent\Model;class LoyaltyRedemption extends Model{protected $fillable=['customer_id','order_id','user_id','points','value'];}
