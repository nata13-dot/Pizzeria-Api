<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class PushService
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->devices()->where('active', true)->pluck('push_token');
        if ($tokens->isEmpty()) {
            return;
        }Http::timeout(8)->post('https://exp.host/--/api/v2/push/send', $tokens->map(fn ($to) => compact('to', 'title', 'body', 'data'))->all())->throw();
    }
}
