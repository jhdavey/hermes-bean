<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarSyncService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';

    public function status(User $user): array
    {
        $connection = $user->googleCalendarConnection()->first();

        return [
            'connected' => $connection?->status === 'connected',
            'status' => $connection?->status ?? 'not_connected',
            'email' => $connection?->google_account_email,
            'calendar_id' => $connection?->calendar_id ?? 'primary',
            'last_synced_at' => $connection?->last_synced_at?->toJSON(),
            'last_error' => $connection?->last_error,
        ];
    }

    public function authorizationUrl(User $user): string
    {
        $this->assertConfigured();

        $state = Str::random(48);
        $user->googleCalendarConnection()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'calendar_id' => 'primary',
                'status' => 'pending',
                'oauth_state' => $state,
                'last_error' => null,
                'last_error_at' => null,
            ]
        );

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeOAuthCallback(string $state, string $code): GoogleCalendarConnection
    {
        $this->assertConfigured();

        $connection = GoogleCalendarConnection::where('oauth_state', $state)->firstOrFail();
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        if (! $response->successful()) {
            $this->markFailed($connection, 'Google OAuth token exchange failed: '.$response->body());
            throw new RuntimeException('Google OAuth token exchange failed.');
        }

        $payload = $response->json();
        $this->storeTokenPayload($connection, $payload);
        $connection->forceFill([
            'status' => 'connected',
            'oauth_state' => null,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        $this->sync($connection->user);

        return $connection->refresh();
    }

    public function disconnect(User $user): void
    {
        $user->googleCalendarConnection()->delete();
        CalendarEvent::where('user_id', $user->id)->whereNotNull('google_event_id')->delete();
    }

    public function sync(User $user): array
    {
        $connection = $user->googleCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Google Calendar is not connected.');
        }

        $token = $this->validAccessToken($connection);
        $query = [
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
            'timeMin' => now()->subMonths(3)->toRfc3339String(),
            'timeMax' => now()->addYear()->toRfc3339String(),
        ];
        if ($connection->sync_token) {
            $query = ['syncToken' => $connection->sync_token, 'showDeleted' => 'true', 'maxResults' => 250];
        }

        $response = Http::withToken($token)->get(self::CALENDAR_API.'/calendars/'.rawurlencode($connection->calendar_id).'/events', $query);
        if ($response->status() === 410 && $connection->sync_token) {
            $connection->forceFill(['sync_token' => null])->save();

            return $this->sync($user);
        }
        if (! $response->successful()) {
            $this->markFailed($connection, 'Google Calendar sync failed: '.$response->body());
            throw new RuntimeException('Google Calendar sync failed.');
        }

        $payload = $response->json();
        $imported = 0;
        $deleted = 0;
        foreach (($payload['items'] ?? []) as $item) {
            if (($item['status'] ?? '') === 'cancelled') {
                $deleted += CalendarEvent::where('user_id', $user->id)->where('google_event_id', $item['id'])->delete();

                continue;
            }
            $startsAt = $this->googleDateTime($item['start'] ?? []);
            if (! $startsAt) {
                continue;
            }
            $endsAt = $this->googleDateTime($item['end'] ?? []) ?? $startsAt;
            CalendarEvent::updateOrCreate(
                ['user_id' => $user->id, 'google_event_id' => $item['id']],
                [
                    'title' => $item['summary'] ?? 'Untitled Google event',
                    'description' => $item['description'] ?? null,
                    'location' => $item['location'] ?? null,
                    'category' => 'Google Calendar',
                    'color' => '#4285F4',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => $item['status'] ?? 'confirmed',
                    'google_updated_at' => isset($item['updated']) ? Carbon::parse($item['updated']) : null,
                    'metadata' => [
                        'source' => 'google_calendar',
                        'google_html_link' => $item['htmlLink'] ?? null,
                        'google_calendar_id' => $connection->calendar_id,
                    ],
                ]
            );
            $imported++;
        }

        $connection->forceFill([
            'status' => 'connected',
            'sync_token' => $payload['nextSyncToken'] ?? $connection->sync_token,
            'last_synced_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return ['imported' => $imported, 'deleted' => $deleted, 'status' => $this->status($user)];
    }

    private function validAccessToken(GoogleCalendarConnection $connection): string
    {
        if ($connection->access_token_encrypted && $connection->token_expires_at?->isFuture()) {
            return Crypt::decryptString($connection->access_token_encrypted);
        }
        if (! $connection->refresh_token_encrypted) {
            throw new RuntimeException('Google Calendar refresh token is missing.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => Crypt::decryptString($connection->refresh_token_encrypted),
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            $this->markFailed($connection, 'Google Calendar token refresh failed: '.$response->body());
            throw new RuntimeException('Google Calendar token refresh failed.');
        }
        $this->storeTokenPayload($connection, $response->json());

        return Crypt::decryptString($connection->refresh()->access_token_encrypted);
    }

    private function storeTokenPayload(GoogleCalendarConnection $connection, array $payload): void
    {
        $updates = [
            'access_token_encrypted' => Crypt::encryptString((string) $payload['access_token']),
            'token_expires_at' => now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 3600) - 60)),
        ];
        if (! empty($payload['refresh_token'])) {
            $updates['refresh_token_encrypted'] = Crypt::encryptString((string) $payload['refresh_token']);
        }
        if (! empty($payload['id_token'])) {
            $updates['metadata'] = array_merge($connection->metadata ?? [], ['id_token_present' => true]);
        }
        $connection->forceFill($updates)->save();
    }

    private function googleDateTime(array $value): ?Carbon
    {
        $date = $value['dateTime'] ?? $value['date'] ?? null;

        return $date ? Carbon::parse($date) : null;
    }

    private function markFailed(GoogleCalendarConnection $connection, string $message): void
    {
        $connection->forceFill(['status' => 'error', 'last_error' => $message, 'last_error_at' => now()])->save();
    }

    private function assertConfigured(): void
    {
        if ($this->clientId() === '' || $this->clientSecret() === '') {
            throw new RuntimeException('Google Calendar OAuth is not configured.');
        }
    }

    private function clientId(): string
    {
        return (string) config('services.google_calendar.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google_calendar.client_secret', '');
    }

    private function redirectUri(): string
    {
        return (string) config('services.google_calendar.redirect_uri', url('/api/google-calendar/callback'));
    }
}
