<?php namespace App\Models;use Illuminate\Database\Eloquent\Model;class OrderPayment extends Model{protected $fillable=['order_id','method','amount','reference','user_id'];}
