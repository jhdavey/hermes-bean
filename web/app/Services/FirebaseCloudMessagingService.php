<?php

namespace App\Services;

use App\Models\PushNotificationDeviceToken;
use App\Models\Reminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseCloudMessagingService
{
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function configured(): bool
    {
        return $this->projectId() !== '' && $this->credentials() !== null;
    }

    public function sendReminder(PushNotificationDeviceToken $deviceToken, Reminder $reminder): bool
    {
        if (! $this->configured() || ! $deviceToken->enabled) {
            return false;
        }

        return $this->sendToToken(
            token: $deviceToken->token,
            title: 'Reminder: '.$reminder->title,
            body: 'Open HeyBean to dismiss or mark it complete.',
            data: [
                'type' => 'reminder_due',
                'reminder_id' => (string) $reminder->id,
                'workspace_id' => (string) ($reminder->workspace_id ?? ''),
                'remind_at' => (string) ($reminder->remind_at?->toIso8601String() ?? ''),
            ],
        );
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        $projectId = $this->projectId();
        if ($projectId === '') {
            return false;
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => collect($data)
                        ->map(fn ($value): string => (string) $value)
                        ->all(),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'heybean_reminders',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::warning('Firebase Cloud Messaging send failed.', [
            'status' => $response->status(),
            'body' => str($response->body())->limit(1000)->toString(),
        ]);

        return false;
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase.fcm.access_token', 3300, function (): string {
            $credentials = $this->credentials();
            if (! $credentials) {
                throw new \RuntimeException('Firebase credentials are not configured.');
            }

            $now = time();
            $jwt = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR)).'.'.$this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::OAUTH_SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            if (! openssl_sign($jwt, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Could not sign Firebase service account assertion.');
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt.'.'.$this->base64UrlEncode($signature),
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Could not get Firebase OAuth token: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private function credentials(): ?array
    {
        $json = (string) config('services.firebase.credentials_json', '');
        if ($json === '') {
            $path = (string) config('services.firebase.credentials_path', '');
            if ($path !== '' && is_file($path)) {
                $json = (string) file_get_contents($path);
            }
        }

        if ($json === '') {
            return null;
        }

        $credentials = json_decode($json, true);
        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            return null;
        }

        return $credentials;
    }

    private function projectId(): string
    {
        return (string) config('services.firebase.project_id', '');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
