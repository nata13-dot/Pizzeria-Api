<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->devices()
            ->where('active', true)
            ->pluck('push_token')
            ->filter(fn (string $pushToken) => str_starts_with($pushToken, 'ExponentPushToken[') || str_starts_with($pushToken, 'ExpoPushToken['));
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            Http::timeout(8)
                ->post('https://exp.host/--/api/v2/push/send', $tokens->map(
                    fn (string $to): array => compact('to', 'title', 'body', 'data'),
                )->values()->all())
                ->throw();
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar la notificación push.', ['user_id' => $user->id, 'exception' => $exception->getMessage()]);
        }
    }
}
