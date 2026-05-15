<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGoogleCalendarMapping;
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
        $calendars = [];
        if ($connection?->status === 'connected') {
            try {
                $calendars = $this->calendarList($connection);
            } catch (RuntimeException) {
                $calendars = $this->storedCalendars($connection);
            }
        }
        $selectedCalendarIds = $connection ? $this->selectedCalendarIds($connection) : [];

        return [
            'connected' => $connection?->status === 'connected',
            'status' => $connection?->status ?? 'not_connected',
            'email' => $connection?->google_account_email,
            'calendar_id' => $connection?->calendar_id ?? 'primary',
            'default_calendar_id' => $connection?->calendar_id ?? 'primary',
            'selected_calendar_ids' => $selectedCalendarIds,
            'calendars' => array_map(fn (array $calendar): array => array_merge($calendar, [
                'selected' => in_array($calendar['id'], $selectedCalendarIds, true),
            ]), $calendars),
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
            'scope' => 'openid email https://www.googleapis.com/auth/calendar',
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
        $this->refreshCalendarList($connection->refresh());

        $this->sync($connection->user);

        return $connection->refresh();
    }

    public function disconnect(User $user): void
    {
        $user->googleCalendarConnection()->delete();
        CalendarEvent::where('user_id', $user->id)->whereNotNull('google_event_id')->delete();
    }

    public function updateSelectedCalendars(User $user, array $selectedCalendarIds, ?string $defaultCalendarId = null): array
    {
        $connection = $this->connectedConnection($user);
        $calendars = $this->calendarList($connection);
        $validIds = collect($calendars)->pluck('id')->all();
        $selectedCalendarIds = array_values(array_intersect(array_unique($selectedCalendarIds), $validIds));
        if ($selectedCalendarIds === []) {
            $selectedCalendarIds = [$connection->calendar_id ?: 'primary'];
        }
        $defaultCalendarId = $defaultCalendarId && in_array($defaultCalendarId, $validIds, true)
            ? $defaultCalendarId
            : $selectedCalendarIds[0];

        $metadata = $connection->metadata ?? [];
        $metadata['selected_calendar_ids'] = $selectedCalendarIds;
        $metadata['calendars'] = $calendars;
        $connection->forceFill([
            'calendar_id' => $defaultCalendarId,
            'metadata' => $metadata,
            'sync_token' => null,
        ])->save();

        return $this->status($user);
    }

    public function sync(User $user, ?Workspace $workspace = null): array
    {
        $connection = $this->connectedConnection($user);
        $workspace ??= Workspace::find(app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user));
        $token = $this->validAccessToken($connection);
        $imported = 0;
        $deleted = 0;
        $metadata = $connection->metadata ?? [];
        $syncTokens = is_array($metadata['sync_tokens'] ?? null) ? $metadata['sync_tokens'] : [];

        foreach ($this->selectedCalendarIds($connection, $workspace) as $calendarId) {
            $query = [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'timeMin' => now()->subMonths(3)->toRfc3339String(),
                'timeMax' => now()->addYear()->toRfc3339String(),
            ];
            if (! empty($syncTokens[$calendarId])) {
                $query = ['syncToken' => $syncTokens[$calendarId], 'showDeleted' => 'true', 'maxResults' => 250];
            }

            $response = Http::withToken($token)->get($this->calendarEventsUrl($calendarId), $query);
            if ($response->status() === 410 && ! empty($syncTokens[$calendarId])) {
                unset($syncTokens[$calendarId]);
                $metadata['sync_tokens'] = $syncTokens;
                $connection->forceFill(['metadata' => $metadata])->save();

                return $this->sync($user);
            }
            if (! $response->successful()) {
                $this->markFailed($connection, 'Google Calendar sync failed: '.$response->body());
                throw new RuntimeException('Google Calendar sync failed.');
            }

            $payload = $response->json();
            foreach (($payload['items'] ?? []) as $item) {
                if (($item['status'] ?? '') === 'cancelled') {
                    $deleted += CalendarEvent::where('workspace_id', $workspace->id)
                        ->where('google_event_id', $item['id'])
                        ->where(function ($query) use ($calendarId): void {
                            $query->where('google_calendar_id', $calendarId)->orWhereNull('google_calendar_id');
                        })
                        ->delete();

                    continue;
                }
                $startsAt = $this->googleDateTime($item['start'] ?? []);
                if (! $startsAt) {
                    continue;
                }
                $isAllDay = isset($item['start']['date']);
                $endsAt = $this->googleDateTime($item['end'] ?? []) ?? $startsAt;
                CalendarEvent::updateOrCreate(
                    ['workspace_id' => $workspace->id, 'google_calendar_id' => $calendarId, 'google_event_id' => $item['id']],
                    [
                        'user_id' => $user->id,
                        'created_by_user_id' => $user->id,
                        'google_calendar_id' => $calendarId,
                        'title' => $item['summary'] ?? 'Untitled Google event',
                        'description' => $item['description'] ?? null,
                        'location' => $item['location'] ?? null,
                        'category' => $this->calendarSummary($connection, $calendarId) ?? 'Google Calendar',
                        'color' => $this->calendarColor($connection, $calendarId) ?? '#4285F4',
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'status' => $item['status'] ?? 'confirmed',
                        'google_updated_at' => isset($item['updated']) ? Carbon::parse($item['updated']) : null,
                        'metadata' => [
                            'source' => 'google_calendar',
                            'google_html_link' => $item['htmlLink'] ?? null,
                            'google_calendar_id' => $calendarId,
                            'google_calendar_summary' => $this->calendarSummary($connection, $calendarId),
                            'all_day' => $isAllDay,
                        ],
                    ]
                );
                $imported++;
            }
            if (! empty($payload['nextSyncToken'])) {
                $syncTokens[$calendarId] = $payload['nextSyncToken'];
            }
        }

        $metadata['sync_tokens'] = $syncTokens;
        $connection->forceFill([
            'status' => 'connected',
            'sync_token' => $syncTokens[$connection->calendar_id] ?? $connection->sync_token,
            'metadata' => $metadata,
            'last_synced_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return ['imported' => $imported, 'deleted' => $deleted, 'status' => $this->status($user)];
    }

    public function syncIfConnected(User $user): void
    {
        try {
            if ($user->googleCalendarConnection()->where('status', 'connected')->exists()) {
                $this->sync($user);
            }
        } catch (RuntimeException) {
            // Keep local calendar usable if Google has a temporary auth/network problem.
        }
    }

    public function exportEvent(CalendarEvent $event): CalendarEvent
    {
        $connection = $event->user?->googleCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            return $event;
        }
        $calendarId = $this->eventGoogleCalendarId($event, $connection);
        if (! $this->calendarCanWrite($connection, $calendarId)) {
            return $event;
        }

        $token = $this->validAccessToken($connection);
        $payload = $this->eventPayload($event);
        $response = $event->google_event_id
            ? Http::withToken($token)->patch($this->calendarEventsUrl($calendarId).'/'.rawurlencode($event->google_event_id), $payload)
            : Http::withToken($token)->post($this->calendarEventsUrl($calendarId), $payload);
        if (! $response->successful()) {
            $this->markFailed($connection, 'Google Calendar event export failed: '.$response->body());
            throw new RuntimeException('Google Calendar event export failed.');
        }

        $googleEvent = $response->json();
        $metadata = $event->metadata ?? [];
        $metadata['source'] = $metadata['source'] ?? 'heybean';
        $metadata['google_html_link'] = $googleEvent['htmlLink'] ?? ($metadata['google_html_link'] ?? null);
        $metadata['google_calendar_id'] = $calendarId;
        $metadata['google_calendar_summary'] = $this->calendarSummary($connection, $calendarId);
        $event->forceFill([
            'google_event_id' => $googleEvent['id'] ?? $event->google_event_id,
            'google_calendar_id' => $calendarId,
            'google_updated_at' => isset($googleEvent['updated']) ? Carbon::parse($googleEvent['updated']) : now(),
            'metadata' => $metadata,
        ])->save();

        return $event->refresh();
    }

    private function connectedConnection(User $user): GoogleCalendarConnection
    {
        $connection = $user->googleCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Google Calendar is not connected.');
        }

        return $connection;
    }

    private function calendarList(GoogleCalendarConnection $connection): array
    {
        $calendars = $this->refreshCalendarList($connection);

        return $calendars ?: $this->storedCalendars($connection);
    }

    private function refreshCalendarList(GoogleCalendarConnection $connection): array
    {
        $response = Http::withToken($this->validAccessToken($connection))->get(self::CALENDAR_API.'/users/me/calendarList', [
            'minAccessRole' => 'reader',
            'showHidden' => 'true',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar list failed.');
        }
        $calendars = array_map(fn (array $item): array => [
            'id' => $item['id'],
            'summary' => $item['summary'] ?? $item['id'],
            'description' => $item['description'] ?? null,
            'primary' => (bool) ($item['primary'] ?? false),
            'access_role' => $item['accessRole'] ?? 'reader',
            'color' => $item['backgroundColor'] ?? '#4285F4',
        ], $response->json('items') ?? []);
        if ($calendars === []) {
            $calendars = [['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'access_role' => 'owner', 'color' => '#4285F4']];
        }
        $metadata = $connection->metadata ?? [];
        $metadata['calendars'] = $calendars;
        $metadata['selected_calendar_ids'] = $metadata['selected_calendar_ids'] ?? [$connection->calendar_id ?: 'primary'];
        $connection->forceFill(['metadata' => $metadata])->save();

        return $calendars;
    }

    private function storedCalendars(GoogleCalendarConnection $connection): array
    {
        $calendars = $connection->metadata['calendars'] ?? null;
        if (is_array($calendars) && $calendars !== []) {
            return $calendars;
        }

        return [['id' => $connection->calendar_id ?: 'primary', 'summary' => 'Primary', 'primary' => true, 'access_role' => 'owner', 'color' => '#4285F4']];
    }

    private function selectedCalendarIds(GoogleCalendarConnection $connection): array
    {
        $selected = $connection->metadata['selected_calendar_ids'] ?? null;
        if (is_array($selected) && $selected !== []) {
            return array_values(array_unique(array_map('strval', $selected)));
        }

        return [$connection->calendar_id ?: 'primary'];
    }

    private function calendarSummary(GoogleCalendarConnection $connection, string $calendarId): ?string
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) === $calendarId) {
                return $calendar['summary'] ?? null;
            }
        }

        return null;
    }

    private function calendarColor(GoogleCalendarConnection $connection, string $calendarId): ?string
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) === $calendarId) {
                return $calendar['color'] ?? null;
            }
        }

        return null;
    }

    private function calendarCanWrite(GoogleCalendarConnection $connection, string $calendarId): bool
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) !== $calendarId) {
                continue;
            }

            return in_array($calendar['access_role'] ?? $calendar['accessRole'] ?? 'reader', ['owner', 'writer'], true);
        }

        return true;
    }

    private function eventGoogleCalendarId(CalendarEvent $event, GoogleCalendarConnection $connection): string
    {
        $metadataCalendarId = $event->metadata['google_calendar_id'] ?? null;

        return $event->google_calendar_id ?: ($metadataCalendarId ? (string) $metadataCalendarId : ($connection->calendar_id ?: 'primary'));
    }

    private function eventPayload(CalendarEvent $event): array
    {
        return [
            'summary' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'start' => ['dateTime' => $event->starts_at->toRfc3339String()],
            'end' => ['dateTime' => ($event->ends_at ?: $event->starts_at)->toRfc3339String()],
        ];
    }

    private function calendarEventsUrl(string $calendarId): string
    {
        return self::CALENDAR_API.'/calendars/'.rawurlencode($calendarId).'/events';
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
