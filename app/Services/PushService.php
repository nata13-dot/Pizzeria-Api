<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PushService
{
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->devices()
            ->where('active', true)
            ->pluck('push_token')
            ->filter();
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $expoTokens = $tokens->filter(fn (string $token): bool => str_starts_with($token, 'ExponentPushToken[') || str_starts_with($token, 'ExpoPushToken['));
            if ($expoTokens->isNotEmpty()) {
                Http::timeout(8)->post('https://exp.host/--/api/v2/push/send', $expoTokens->map(
                    fn (string $to): array => compact('to', 'title', 'body', 'data') + [
                        'sound' => 'campanilla.wav',
                        'channelId' => 'orders_kitchen_bell',
                        'priority' => 'high',
                    ],
                )->values()->all())->throw();
            }

            $fcmTokens = $tokens->diff($expoTokens);
            foreach ($fcmTokens as $fcmToken) {
                $this->sendFcm($fcmToken, $title, $body, $data);
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar la notificación push.', ['user_id' => $user->id, 'exception' => $exception->getMessage()]);
        }
    }

    private function sendFcm(string $deviceToken, string $title, string $body, array $data): void
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
                            'channel_id' => 'orders_kitchen_bell',
                            'sound' => 'campanilla',
                            'default_vibrate_timings' => true,
                            'notification_priority' => 'PRIORITY_HIGH',
                        ],
                    ],
                    'apns' => ['payload' => ['aps' => ['sound' => 'campanilla.wav']]],
                ],
            ])->throw();
    }

    private function firebaseCredentials(): ?array
    {
        $raw = trim((string) config('services.firebase_service_account_json'));
        if ($raw === '') return null;
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) $decoded = json_decode((string) base64_decode($raw, true), true);

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
