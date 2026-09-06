<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $fillable = ['user_id', 'name', 'platform', 'push_token', 'notification_sound_mode', 'notification_channels', 'last_seen_at', 'active'];

    protected function casts(): array
    {
        return ['notification_channels' => 'array', 'last_seen_at' => 'datetime', 'active' => 'boolean'];
    }
}
