<?php namespace App\Models;use Illuminate\Database\Eloquent\Model;class CustomerAddress extends Model{protected $fillable=['customer_id','label','address','references','map_url','is_default'];}
