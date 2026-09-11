<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $devices = $user->devices()
            ->where('active', true)
            ->get()
            ->filter(fn ($device) => filled($device->push_token));
        if ($devices->isEmpty()) {
            return;
        }

        try {
            $expoDevices = $devices->filter(fn ($device): bool => str_starts_with($device->push_token, 'ExponentPushToken[') || str_starts_with($device->push_token, 'ExpoPushToken['));
            if ($expoDevices->isNotEmpty()) {
                Http::timeout(8)->post('https://exp.host/--/api/v2/push/send', $expoDevices->map(
                    function ($device) use ($title, $body, $data): array {
                        $to = $device->push_token;
                        $sound = $this->notificationSound($device);

                        return compact('to', 'title', 'body', 'data') + [
                            'sound' => $sound['file'],
                            'channelId' => $sound['channel'],
                            'priority' => 'high',
                        ];
                    },
                )->values()->all())->throw();
            }

            foreach ($devices->reject(fn ($device): bool => str_starts_with($device->push_token, 'ExponentPushToken[') || str_starts_with($device->push_token, 'ExpoPushToken[')) as $device) {
                $this->sendFcm($device->push_token, $title, $body, $data, $this->notificationSound($device));
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar la notificación push.', ['user_id' => $user->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function sendFcm(string $deviceToken, string $title, string $body, array $data, array $sound): void
    {
        $credentials = $this->firebaseCredentials();
        if (! $credentials) {
            Log::warning('FCM no está configurado: falta FIREBASE_SERVICE_ACCOUNT_JSON.');

            return;
        }

        Http::withToken($this->firebaseAccessToken($credentials))
            ->timeout(8)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => compact('title', 'body'),
                    'data' => collect($data)->map(fn ($value): string => (string) $value)->all(),
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => $sound['channel'],
                            'sound' => pathinfo($sound['file'], PATHINFO_FILENAME),
                            'default_vibrate_timings' => true,
                            'notification_priority' => 'PRIORITY_HIGH',
                        ],
                    ],
                    'apns' => ['payload' => ['aps' => ['sound' => $sound['file']]]],
                ],
            ])->throw();
    }

    private function notificationSound($device): array
    {
        $channels = array_values(array_filter((array) $device->notification_channels, fn ($channel) => is_string($channel) && preg_match('/^orders_arrival_(tone_v3_(default|bell|kitchen|soft|ding)|custom_v1_custom_[a-z0-9_]+)$/', $channel)));
        $channels = $channels ?: ['orders_arrival_tone_v3_default'];
        $channel = $device->notification_sound_mode === 'random' ? $channels[array_rand($channels)] : $channels[0];
        $files = [
            'default' => 'notification_arrival.wav', 'bell' => 'campanilla.wav', 'kitchen' => 'kitchen_sent.mp3', 'soft' => 'modal_open.mp3', 'ding' => 'navigation_ding.mp3',
        ];
        preg_match('/tone_v3_(default|bell|kitchen|soft|ding)$/', $channel, $match);

        return ['file' => $files[$match[1] ?? 'default'], 'channel' => $channel];
    }

    private function firebaseCredentials(): ?array
    {
        $raw = trim((string) config('services.firebase_service_account_json'));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = json_decode((string) base64_decode($raw, true), true);
        }

        return is_array($decoded) && isset($decoded['project_id'], $decoded['client_email'], $decoded['private_key']) ? $decoded : null;
    }

    private function firebaseAccessToken(array $credentials): string
    {
        return Cache::remember('firebase-access-token-'.$credentials['project_id'], now()->addMinutes(50), function () use ($credentials): string {
            $now = time();
            $encode = fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);
            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('No se pudo firmar la credencial de Firebase.');
            }
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $response = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json();

            return $response['access_token'];
        });
    }
}
