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

    public function status(User $user, bool $refreshCalendars = true): array
    {
        $connection = $user->googleCalendarConnection()->first();
        $calendars = [];
        if ($connection?->status === 'connected') {
            try {
                $calendars = $refreshCalendars ? $this->calendarList($connection) : $this->storedCalendars($connection);
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
            $this->markFailed($connection, 'Google OAuth token exchange failed.');
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
        CalendarEvent::where('user_id', $user->id)
            ->whereNotNull('google_event_id')
            ->get()
            ->each(fn (CalendarEvent $event): ?bool => $event->delete());
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
        $workspace ??= app(WorkspaceService::class)->resolveWorkspace($user);
        $token = $this->validAccessToken($connection);
        $imported = 0;
        $deleted = 0;
        $metadata = $connection->metadata ?? [];
        $syncTokens = is_array($metadata['sync_tokens'] ?? null) ? $metadata['sync_tokens'] : [];
        if (($metadata['google_datetime_import_mode'] ?? null) !== 'instant_v1') {
            $syncTokens = [];
            $metadata['sync_tokens'] = [];
            $metadata['google_datetime_import_mode'] = 'instant_v1';
            $connection->forceFill(['sync_token' => null, 'metadata' => $metadata])->save();
        }

        foreach ($this->selectedCalendarIds($connection, $workspace) as $calendarId) {
            $syncTokenKey = $this->syncTokenKey($workspace, $calendarId);
            $query = [
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'timeMin' => now()->subMonths(3)->toRfc3339String(),
                'timeMax' => now()->addYear()->toRfc3339String(),
            ];
            if (! empty($syncTokens[$syncTokenKey])) {
                $query = ['syncToken' => $syncTokens[$syncTokenKey], 'showDeleted' => 'true', 'maxResults' => 250];
            }

            $response = Http::withToken($token)->get($this->calendarEventsUrl($calendarId), $query);
            if ($response->status() === 410 && ! empty($syncTokens[$syncTokenKey])) {
                unset($syncTokens[$syncTokenKey]);
                $metadata['sync_tokens'] = $syncTokens;
                $connection->forceFill(['metadata' => $metadata])->save();

                return $this->sync($user, $workspace);
            }
            if (! $response->successful()) {
                $this->markFailed($connection, 'Calendar sync failed.');
                throw new RuntimeException('Calendar sync failed.');
            }

            $payload = $response->json();
            foreach (($payload['items'] ?? []) as $item) {
                if (($item['status'] ?? '') === 'cancelled') {
                    $cancelledEvents = CalendarEvent::where('workspace_id', $workspace->id)
                        ->where('google_event_id', $item['id'])
                        ->where(function ($query) use ($calendarId): void {
                            $query->where('google_calendar_id', $calendarId)->orWhereNull('google_calendar_id');
                        })
                        ->get();
                    $cancelledEvents->each(fn (CalendarEvent $event): ?bool => $event->delete());
                    $deleted += $cancelledEvents->count();

                    continue;
                }
                $startsAt = $this->googleDateTime($item['start'] ?? []);
                if (! $startsAt) {
                    continue;
                }
                $isAllDay = isset($item['start']['date']);
                $endsAt = $this->googleDateTime($item['end'] ?? []) ?? $startsAt;
                $event = $this->existingGoogleEvent($workspace, $connection, $calendarId, (string) $item['id']);
                $existingMetadata = $event->exists ? ($event->metadata ?? []) : [];
                $preserveHeyBeanSource = $this->isHeyBeanExportedEvent($event);
                $preserveEventCategory = $preserveHeyBeanSource || $this->shouldPreserveExistingCategory($event);
                $calendarSummary = $this->calendarSummary($connection, $calendarId);
                $eventMetadata = array_merge($existingMetadata, [
                    'source' => $preserveHeyBeanSource ? ($existingMetadata['source'] ?? 'heybean') : 'google_calendar',
                    'google_html_link' => $item['htmlLink'] ?? null,
                    'google_calendar_id' => $calendarId,
                    'google_calendar_summary' => $calendarSummary,
                    'all_day' => $isAllDay,
                    'last_synced_from_google_at' => now()->toIso8601String(),
                ]);
                $event->forceFill([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'created_by_user_id' => $event->created_by_user_id ?: $user->id,
                    'google_calendar_id' => $calendarId,
                    'google_event_id' => $item['id'],
                    'title' => $item['summary'] ?? 'Untitled Google event',
                    'description' => $item['description'] ?? null,
                    'location' => $item['location'] ?? null,
                    'category' => $preserveEventCategory ? $event->category : $this->calendarCategoryLabel($connection, $calendarId),
                    'color' => $preserveEventCategory ? $event->color : ($this->calendarColor($connection, $calendarId) ?? '#4285F4'),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => $item['status'] ?? 'confirmed',
                    'google_updated_at' => isset($item['updated']) ? Carbon::parse($item['updated']) : null,
                    'metadata' => $eventMetadata,
                ])->save();
                $this->deleteDuplicateGoogleEventAliases($event, $connection, $calendarId);
                $imported++;
            }
            if (! empty($payload['nextSyncToken'])) {
                $syncTokens[$syncTokenKey] = $payload['nextSyncToken'];
            }
        }

        $metadata['sync_tokens'] = $syncTokens;
        $connection->forceFill([
            'status' => 'connected',
            'sync_token' => $syncTokens[$this->syncTokenKey($workspace, $connection->calendar_id ?: 'primary')] ?? $connection->sync_token,
            'metadata' => $metadata,
            'last_synced_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return ['imported' => $imported, 'deleted' => $deleted, 'status' => $this->status($user)];
    }

    public function syncIfConnected(User $user, ?Workspace $workspace = null): void
    {
        try {
            if ($user->googleCalendarConnection()->where('status', 'connected')->exists()) {
                $this->sync($user, $workspace);
            }
        } catch (RuntimeException) {
            // Keep local calendar usable if Google has a temporary auth/network problem.
        }
    }

    public function visibleGoogleCalendarIdsForWorkspace(User $user, Workspace $workspace): ?array
    {
        $connection = $user->googleCalendarConnection()->where('status', 'connected')->first();
        if (! $connection) {
            return null;
        }

        return $this->expandPrimaryCalendarAliases($connection, $this->selectedCalendarIds($connection, $workspace));
    }

    public function clearWorkspaceSyncTokens(GoogleCalendarConnection $connection, Workspace $workspace): void
    {
        $metadata = $connection->metadata ?? [];
        $syncTokens = $metadata['sync_tokens'] ?? [];
        if (! is_array($syncTokens) || $syncTokens === []) {
            return;
        }

        $prefix = $workspace->id.':';
        foreach (array_keys($syncTokens) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($syncTokens[$key]);
            }
        }

        $metadata['sync_tokens'] = $syncTokens;
        $connection->forceFill(['metadata' => $metadata])->save();
    }

    private function connectedConnection(User $user): GoogleCalendarConnection
    {
        $connection = $user->googleCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Calendar sync is not connected.');
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
            throw new RuntimeException('Calendar list failed.');
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

    private function selectedCalendarIds(GoogleCalendarConnection $connection, ?Workspace $workspace = null): array
    {
        if ($workspace !== null) {
            $workspaceSelected = WorkspaceGoogleCalendarMapping::query()
                ->where('workspace_id', $workspace->id)
                ->pluck('google_calendar_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            if ($workspaceSelected !== [] || (bool) (($workspace->settings ?? [])['google_calendar_mappings_configured'] ?? false)) {
                return $this->dedupePrimaryCalendarAliases($connection, $workspaceSelected);
            }
        }

        $selected = $connection->metadata['selected_calendar_ids'] ?? null;
        if (is_array($selected) && $selected !== []) {
            return $this->dedupePrimaryCalendarAliases($connection, array_map('strval', $selected));
        }

        return [$connection->calendar_id ?: 'primary'];
    }

    private function syncTokenKey(Workspace $workspace, string $calendarId): string
    {
        return $workspace->id.':'.$calendarId;
    }

    private function expandPrimaryCalendarAliases(GoogleCalendarConnection $connection, array $calendarIds): array
    {
        $calendarIds = array_values(array_unique(array_map('strval', $calendarIds)));
        if (array_intersect($calendarIds, $this->primaryCalendarAliases($connection)) !== []) {
            return array_values(array_unique(array_merge($calendarIds, $this->primaryCalendarAliases($connection))));
        }

        return $calendarIds;
    }

    private function dedupePrimaryCalendarAliases(GoogleCalendarConnection $connection, array $calendarIds): array
    {
        $calendarIds = array_values(array_unique(array_map('strval', $calendarIds)));
        $primaryAliases = $this->primaryCalendarAliases($connection);
        if (count(array_intersect($calendarIds, $primaryAliases)) <= 1) {
            return $calendarIds;
        }

        $preferred = in_array('primary', $calendarIds, true) ? 'primary' : array_values(array_intersect($calendarIds, $primaryAliases))[0];

        return array_values(array_unique(array_map(
            fn (string $calendarId): string => in_array($calendarId, $primaryAliases, true) ? $preferred : $calendarId,
            $calendarIds,
        )));
    }

    private function primaryCalendarAliases(GoogleCalendarConnection $connection): array
    {
        $aliases = ['primary'];
        if ($connection->google_account_email) {
            $aliases[] = (string) $connection->google_account_email;
        }
        foreach ($this->storedCalendars($connection) as $calendar) {
            if ((bool) ($calendar['primary'] ?? false) && ! empty($calendar['id'])) {
                $aliases[] = (string) $calendar['id'];
            }
        }

        return array_values(array_unique($aliases));
    }

    private function existingGoogleEvent(Workspace $workspace, GoogleCalendarConnection $connection, string $calendarId, string $googleEventId): CalendarEvent
    {
        $calendarAliases = in_array($calendarId, $this->primaryCalendarAliases($connection), true)
            ? $this->primaryCalendarAliases($connection)
            : [$calendarId];

        $existing = CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('google_event_id', $googleEventId)
            ->where(function ($query) use ($calendarAliases): void {
                $query->whereIn('google_calendar_id', $calendarAliases)
                    ->orWhereNull('google_calendar_id')
                    ->orWhere(function ($query) use ($calendarAliases): void {
                        foreach ($calendarAliases as $alias) {
                            $query->orWhere('metadata->google_calendar_id', $alias);
                        }
                    });
            })
            ->orderByRaw('google_calendar_id is null')
            ->oldest('id')
            ->first();

        return $existing ?: new CalendarEvent;
    }

    private function deleteDuplicateGoogleEventAliases(CalendarEvent $event, GoogleCalendarConnection $connection, string $calendarId): void
    {
        if (! $event->google_event_id || ! $event->workspace_id) {
            return;
        }

        $calendarAliases = in_array($calendarId, $this->primaryCalendarAliases($connection), true)
            ? $this->primaryCalendarAliases($connection)
            : [$calendarId];

        CalendarEvent::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('google_event_id', $event->google_event_id)
            ->whereKeyNot($event->id)
            ->where(function ($query) use ($calendarAliases): void {
                $query->whereIn('google_calendar_id', $calendarAliases)
                    ->orWhereNull('google_calendar_id')
                    ->orWhere(function ($query) use ($calendarAliases): void {
                        foreach ($calendarAliases as $alias) {
                            $query->orWhere('metadata->google_calendar_id', $alias);
                        }
                    });
            })
            ->get()
            ->each(fn (CalendarEvent $event): ?bool => $event->delete());
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

    private function calendarCategoryLabel(GoogleCalendarConnection $connection, string $calendarId): string
    {
        $summary = trim((string) ($this->calendarSummary($connection, $calendarId) ?? ''));

        return $summary !== '' && ! filter_var($summary, FILTER_VALIDATE_EMAIL)
            ? $summary
            : 'Connected calendar';
    }

    private function shouldPreserveExistingCategory(CalendarEvent $event): bool
    {
        if (! $event->exists) {
            return false;
        }

        $category = trim((string) ($event->category ?? ''));

        return $category !== '' && ! filter_var($category, FILTER_VALIDATE_EMAIL);
    }

    private function isHeyBeanExportedEvent(CalendarEvent $event): bool
    {
        if (! $event->exists) {
            return false;
        }

        $metadata = $event->metadata ?? [];

        return ($metadata['source'] ?? null) === 'heybean'
            || is_array($metadata['google_event_exports'] ?? null);
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
            throw new RuntimeException('Calendar refresh token is missing.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => Crypt::decryptString($connection->refresh_token_encrypted),
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            $this->markFailed($connection, 'Calendar token refresh failed.');
            throw new RuntimeException('Calendar token refresh failed.');
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
        if (! empty($value['date'])) {
            return Carbon::parse((string) $value['date'])->startOfDay();
        }

        $dateTime = $value['dateTime'] ?? null;
        if (! is_string($dateTime) || trim($dateTime) === '') {
            return null;
        }

        // Google timed events include the actual instant plus the calendar's offset
        // (for example 3:15 PM America/New_York as 2026-05-20T15:15:00-04:00).
        // Preserve that instant in UTC so Flutter can parse the API's Z timestamp
        // and render it back in the user's device-local timezone. Stripping the
        // offset here makes the next app refresh display the event one timezone
        // offset earlier (3:15 PM -> 11:15 AM during EDT).
        return Carbon::parse($dateTime)->utc();
    }

    private function markFailed(GoogleCalendarConnection $connection, string $message): void
    {
        $connection->forceFill(['status' => 'error', 'last_error' => $message, 'last_error_at' => now()])->save();
    }

    private function assertConfigured(): void
    {
        if ($this->clientId() === '' || $this->clientSecret() === '') {
            throw new RuntimeException('Calendar authorization is not configured.');
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
