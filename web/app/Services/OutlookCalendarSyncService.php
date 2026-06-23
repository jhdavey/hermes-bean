<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\OutlookCalendarConnection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OutlookCalendarSyncService
{
    private const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const GRAPH_API = 'https://graph.microsoft.com/v1.0';

    public function status(User $user, bool $refreshCalendars = true): array
    {
        $connection = $user->outlookCalendarConnection()->first();
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
            'email' => $connection?->outlook_account_email,
            'calendar_id' => $connection?->calendar_id,
            'default_calendar_id' => $connection?->calendar_id,
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
        $user->outlookCalendarConnection()->updateOrCreate(
            ['user_id' => $user->id],
            [
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
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeOAuthCallback(string $state, string $code): OutlookCalendarConnection
    {
        $this->assertConfigured();

        $connection = OutlookCalendarConnection::where('oauth_state', $state)->firstOrFail();
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ]);

        if (! $response->successful()) {
            $this->markFailed($connection, 'Outlook OAuth token exchange failed.');
            throw new RuntimeException('Outlook OAuth token exchange failed.');
        }

        $this->storeTokenPayload($connection, $response->json());
        $connection->forceFill([
            'status' => 'connected',
            'oauth_state' => null,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
        $this->refreshAccount($connection->refresh());
        $this->refreshCalendarList($connection->refresh());

        $this->sync($connection->user);

        return $connection->refresh();
    }

    public function disconnect(User $user): void
    {
        $user->outlookCalendarConnection()->delete();
        CalendarEvent::where('user_id', $user->id)
            ->whereNotNull('outlook_event_id')
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
            $selectedCalendarIds = [$connection->calendar_id ?: ($validIds[0] ?? 'primary')];
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

        foreach ($this->selectedCalendarIds($connection) as $calendarId) {
            $response = Http::withToken($token)
                ->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])
                ->get($this->calendarViewUrl($calendarId), [
                    'startDateTime' => now()->subMonths(3)->utc()->format('Y-m-d\TH:i:s\Z'),
                    'endDateTime' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
                    '$top' => 250,
                    '$orderby' => 'start/dateTime',
                ]);
            if (! $response->successful()) {
                $this->markFailed($connection, 'Outlook calendar sync failed.');
                throw new RuntimeException('Outlook calendar sync failed.');
            }

            foreach (($response->json('value') ?? []) as $item) {
                if (($item['isCancelled'] ?? false) === true) {
                    $cancelledEvents = CalendarEvent::where('workspace_id', $workspace->id)
                        ->where('outlook_event_id', $item['id'])
                        ->where('outlook_calendar_id', $calendarId)
                        ->get();
                    $cancelledEvents->each(fn (CalendarEvent $event): ?bool => $event->delete());
                    $deleted += $cancelledEvents->count();

                    continue;
                }

                $startsAt = $this->graphDateTime($item['start'] ?? [], (bool) ($item['isAllDay'] ?? false));
                if (! $startsAt) {
                    continue;
                }
                $endsAt = $this->graphDateTime($item['end'] ?? [], (bool) ($item['isAllDay'] ?? false)) ?? $startsAt;
                $event = $this->existingOutlookEvent($workspace, $calendarId, (string) $item['id']);
                $existingMetadata = $event->exists ? ($event->metadata ?? []) : [];
                $preserveHeyBeanSource = $this->isHeyBeanExportedEvent($event);
                $calendarSummary = $this->calendarSummary($connection, $calendarId);
                $eventMetadata = array_merge($existingMetadata, [
                    'source' => $preserveHeyBeanSource ? ($existingMetadata['source'] ?? 'heybean') : 'outlook_calendar',
                    'outlook_web_link' => $item['webLink'] ?? null,
                    'outlook_calendar_id' => $calendarId,
                    'outlook_calendar_summary' => $calendarSummary,
                    'all_day' => (bool) ($item['isAllDay'] ?? false),
                    'last_synced_from_outlook_at' => now()->toIso8601String(),
                ]);
                $event->forceFill([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'created_by_user_id' => $event->created_by_user_id ?: $user->id,
                    'outlook_calendar_id' => $calendarId,
                    'outlook_event_id' => $item['id'],
                    'title' => $item['subject'] ?? 'Untitled Outlook event',
                    'description' => $item['bodyPreview'] ?? null,
                    'location' => $item['location']['displayName'] ?? null,
                    'category' => $preserveHeyBeanSource ? $event->category : $this->calendarCategoryLabel($connection, $calendarId),
                    'color' => $preserveHeyBeanSource ? $event->color : ($this->calendarColor($connection, $calendarId) ?? '#0078D4'),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'status' => 'confirmed',
                    'outlook_updated_at' => isset($item['lastModifiedDateTime']) ? Carbon::parse($item['lastModifiedDateTime']) : null,
                    'metadata' => $eventMetadata,
                ])->save();
                $imported++;
            }
        }

        $connection->forceFill([
            'status' => 'connected',
            'last_synced_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return ['imported' => $imported, 'deleted' => $deleted, 'status' => $this->status($user)];
    }

    public function syncIfConnected(User $user, ?Workspace $workspace = null): void
    {
        try {
            if ($user->outlookCalendarConnection()->where('status', 'connected')->exists()) {
                $this->sync($user, $workspace);
            }
        } catch (RuntimeException) {
            // Keep local calendar usable if Outlook has a temporary auth/network problem.
        }
    }

    public function exportEvent(CalendarEvent $event): CalendarEvent
    {
        $connection = $event->user?->outlookCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            return $event;
        }
        $calendarIds = array_values(array_filter(
            array_unique($this->eventOutlookCalendarIds($event)),
            fn (string $calendarId): bool => $this->calendarCanWrite($connection, $calendarId)
        ));
        $metadata = $event->metadata ?? [];
        $exports = is_array($metadata['outlook_event_exports'] ?? null) ? $metadata['outlook_event_exports'] : [];
        if ($event->outlook_calendar_id && $event->outlook_event_id) {
            $exports[$event->outlook_calendar_id] ??= ['event_id' => $event->outlook_event_id];
        }
        $token = $this->validAccessToken($connection);
        foreach (array_diff(array_keys($exports), $calendarIds) as $removedCalendarId) {
            $removedEventId = is_array($exports[$removedCalendarId] ?? null) ? ($exports[$removedCalendarId]['event_id'] ?? null) : null;
            if ($removedEventId && $this->calendarCanWrite($connection, (string) $removedCalendarId)) {
                $response = Http::withToken($token)->delete($this->calendarEventsUrl((string) $removedCalendarId).'/'.rawurlencode((string) $removedEventId));
                if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
                    $this->markFailed($connection, 'Outlook event delete failed.');
                    throw new RuntimeException('Outlook event delete failed.');
                }
            }
            unset($exports[$removedCalendarId]);
        }

        if ($calendarIds === []) {
            $metadata['source'] = $metadata['source'] ?? 'heybean';
            $metadata['outlook_event_exports'] = $exports;
            $metadata['outlook_calendar_ids'] = [];
            unset($metadata['outlook_calendar_id'], $metadata['outlook_calendar_summary'], $metadata['outlook_web_link']);
            $event->forceFill([
                'outlook_event_id' => null,
                'outlook_calendar_id' => null,
                'outlook_updated_at' => null,
                'metadata' => $metadata,
            ])->save();

            return $event->refresh();
        }

        $payload = $this->eventPayload($event);
        $primaryOutlookEvent = null;
        $primaryCalendarId = null;
        foreach ($calendarIds as $calendarId) {
            $existingOutlookEventId = $exports[$calendarId]['event_id'] ?? ($calendarId === $event->outlook_calendar_id ? $event->outlook_event_id : null);
            $response = $existingOutlookEventId
                ? Http::withToken($token)->patch($this->calendarEventsUrl($calendarId).'/'.rawurlencode($existingOutlookEventId), $payload)
                : Http::withToken($token)->post($this->calendarEventsUrl($calendarId), $payload);
            if (! $response->successful()) {
                $this->markFailed($connection, 'Outlook event export failed.');
                throw new RuntimeException('Outlook event export failed.');
            }

            $outlookEvent = $response->json();
            $exports[$calendarId] = [
                'event_id' => $outlookEvent['id'] ?? $existingOutlookEventId,
                'web_link' => $outlookEvent['webLink'] ?? ($exports[$calendarId]['web_link'] ?? null),
                'updated_at' => $outlookEvent['lastModifiedDateTime'] ?? now()->toIso8601String(),
            ];
            $primaryOutlookEvent ??= $outlookEvent;
            $primaryCalendarId ??= $calendarId;
        }

        $metadata['source'] = $metadata['source'] ?? 'heybean';
        $metadata['outlook_event_exports'] = $exports;
        $metadata['outlook_calendar_ids'] = $calendarIds;
        $metadata['outlook_web_link'] = $primaryOutlookEvent['webLink'] ?? ($metadata['outlook_web_link'] ?? null);
        $metadata['outlook_calendar_id'] = $primaryCalendarId;
        $metadata['outlook_calendar_summary'] = $this->calendarSummary($connection, $primaryCalendarId);
        $event->forceFill([
            'outlook_event_id' => $primaryOutlookEvent['id'] ?? $event->outlook_event_id,
            'outlook_calendar_id' => $primaryCalendarId,
            'outlook_updated_at' => isset($primaryOutlookEvent['lastModifiedDateTime']) ? Carbon::parse($primaryOutlookEvent['lastModifiedDateTime']) : now(),
            'metadata' => $metadata,
        ])->save();

        return $event->refresh();
    }

    public function deleteExportedEvent(CalendarEvent $event): void
    {
        $connection = $event->user?->outlookCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            return;
        }

        $metadata = $event->metadata ?? [];
        $exports = is_array($metadata['outlook_event_exports'] ?? null) ? $metadata['outlook_event_exports'] : [];
        if ($event->outlook_calendar_id && $event->outlook_event_id) {
            $exports[$event->outlook_calendar_id] ??= ['event_id' => $event->outlook_event_id];
        }

        if ($exports === []) {
            return;
        }

        $token = $this->validAccessToken($connection);
        foreach ($exports as $calendarId => $export) {
            $outlookEventId = is_array($export) ? ($export['event_id'] ?? null) : null;
            if (! $calendarId || ! $outlookEventId || ! $this->calendarCanWrite($connection, (string) $calendarId)) {
                continue;
            }

            $response = Http::withToken($token)->delete($this->calendarEventsUrl((string) $calendarId).'/'.rawurlencode((string) $outlookEventId));
            if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
                $this->markFailed($connection, 'Outlook event delete failed.');
                throw new RuntimeException('Outlook event delete failed.');
            }
        }
    }

    private function connectedConnection(User $user): OutlookCalendarConnection
    {
        $connection = $user->outlookCalendarConnection()->first();
        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Outlook calendar sync is not connected.');
        }

        return $connection;
    }

    private function refreshAccount(OutlookCalendarConnection $connection): void
    {
        $response = Http::withToken($this->validAccessToken($connection))->get(self::GRAPH_API.'/me');
        if (! $response->successful()) {
            return;
        }
        $connection->forceFill([
            'outlook_account_email' => $response->json('mail') ?: $response->json('userPrincipalName'),
        ])->save();
    }

    private function calendarList(OutlookCalendarConnection $connection): array
    {
        $calendars = $this->refreshCalendarList($connection);

        return $calendars ?: $this->storedCalendars($connection);
    }

    private function refreshCalendarList(OutlookCalendarConnection $connection): array
    {
        $response = Http::withToken($this->validAccessToken($connection))->get(self::GRAPH_API.'/me/calendars', ['$top' => 100]);
        if (! $response->successful()) {
            throw new RuntimeException('Outlook calendar list failed.');
        }
        $calendars = array_map(fn (array $item): array => [
            'id' => $item['id'],
            'summary' => $item['name'] ?? $item['id'],
            'description' => null,
            'primary' => (bool) ($item['isDefaultCalendar'] ?? false),
            'access_role' => ($item['canEdit'] ?? true) ? 'writer' : 'reader',
            'color' => '#0078D4',
        ], $response->json('value') ?? []);
        if ($calendars === []) {
            $calendars = [['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'access_role' => 'writer', 'color' => '#0078D4']];
        }
        $primaryCalendar = collect($calendars)->firstWhere('primary', true);
        $defaultCalendarId = is_array($primaryCalendar) ? $primaryCalendar['id'] : $calendars[0]['id'];
        $metadata = $connection->metadata ?? [];
        $metadata['calendars'] = $calendars;
        $metadata['selected_calendar_ids'] = $metadata['selected_calendar_ids'] ?? [$defaultCalendarId];
        $connection->forceFill([
            'calendar_id' => $connection->calendar_id ?: $defaultCalendarId,
            'metadata' => $metadata,
        ])->save();

        return $calendars;
    }

    private function storedCalendars(OutlookCalendarConnection $connection): array
    {
        $calendars = $connection->metadata['calendars'] ?? null;
        if (is_array($calendars) && $calendars !== []) {
            return $calendars;
        }

        return [['id' => $connection->calendar_id ?: 'primary', 'summary' => 'Primary', 'primary' => true, 'access_role' => 'writer', 'color' => '#0078D4']];
    }

    private function selectedCalendarIds(OutlookCalendarConnection $connection): array
    {
        $selected = $connection->metadata['selected_calendar_ids'] ?? null;
        if (is_array($selected) && $selected !== []) {
            return array_values(array_unique(array_map('strval', $selected)));
        }

        return [$connection->calendar_id ?: 'primary'];
    }

    private function existingOutlookEvent(Workspace $workspace, string $calendarId, string $outlookEventId): CalendarEvent
    {
        $existing = CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('outlook_event_id', $outlookEventId)
            ->where(function ($query) use ($calendarId): void {
                $query->where('outlook_calendar_id', $calendarId)
                    ->orWhereNull('outlook_calendar_id')
                    ->orWhere('metadata->outlook_calendar_id', $calendarId);
            })
            ->orderByRaw('outlook_calendar_id is null')
            ->oldest('id')
            ->first();

        return $existing ?: new CalendarEvent;
    }

    private function calendarSummary(OutlookCalendarConnection $connection, ?string $calendarId): ?string
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) === $calendarId) {
                return $calendar['summary'] ?? null;
            }
        }

        return null;
    }

    private function calendarCategoryLabel(OutlookCalendarConnection $connection, string $calendarId): string
    {
        $summary = trim((string) ($this->calendarSummary($connection, $calendarId) ?? ''));

        return $summary !== '' && ! filter_var($summary, FILTER_VALIDATE_EMAIL)
            ? $summary
            : 'Connected calendar';
    }

    private function calendarColor(OutlookCalendarConnection $connection, string $calendarId): ?string
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) === $calendarId) {
                return $calendar['color'] ?? null;
            }
        }

        return null;
    }

    private function calendarCanWrite(OutlookCalendarConnection $connection, string $calendarId): bool
    {
        foreach ($this->storedCalendars($connection) as $calendar) {
            if (($calendar['id'] ?? null) !== $calendarId) {
                continue;
            }

            return in_array($calendar['access_role'] ?? $calendar['accessRole'] ?? 'reader', ['owner', 'writer'], true);
        }

        return true;
    }

    private function isHeyBeanExportedEvent(CalendarEvent $event): bool
    {
        if (! $event->exists) {
            return false;
        }

        $metadata = $event->metadata ?? [];

        return ($metadata['source'] ?? null) === 'heybean'
            || is_array($metadata['outlook_event_exports'] ?? null);
    }

    private function eventOutlookCalendarIds(CalendarEvent $event): array
    {
        $metadataCalendarIds = $event->metadata['outlook_calendar_ids'] ?? null;
        if (is_array($metadataCalendarIds)) {
            return array_values(array_filter(array_map('strval', $metadataCalendarIds)));
        }

        $metadataCalendarId = $event->metadata['outlook_calendar_id'] ?? null;
        if (is_string($metadataCalendarId) && trim($metadataCalendarId) !== '') {
            return [(string) $metadataCalendarId];
        }

        return [];
    }

    private function eventPayload(CalendarEvent $event): array
    {
        $metadata = $event->metadata ?? [];
        $allDay = ($metadata['all_day'] ?? $metadata['allDay'] ?? false) === true
            || in_array(strtolower((string) ($metadata['all_day'] ?? $metadata['allDay'] ?? '')), ['true', '1'], true);

        $start = $event->starts_at->copy()->utc();
        $end = ($event->ends_at ?: $event->starts_at)->copy()->utc();
        if ($allDay) {
            $start = $event->starts_at->copy()->startOfDay()->utc();
            $end = ($event->ends_at ?: $event->starts_at->copy()->addDay())->copy()->startOfDay()->utc();
        }

        return [
            'subject' => $event->title,
            'body' => ['contentType' => 'HTML', 'content' => nl2br(e($event->description ?? ''))],
            'location' => ['displayName' => $event->location ?? ''],
            'isAllDay' => $allDay,
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        ];
    }

    private function calendarEventsUrl(string $calendarId): string
    {
        return $calendarId === 'primary'
            ? self::GRAPH_API.'/me/calendar/events'
            : self::GRAPH_API.'/me/calendars/'.rawurlencode($calendarId).'/events';
    }

    private function calendarViewUrl(string $calendarId): string
    {
        return $calendarId === 'primary'
            ? self::GRAPH_API.'/me/calendar/calendarView'
            : self::GRAPH_API.'/me/calendars/'.rawurlencode($calendarId).'/calendarView';
    }

    private function graphDateTime(array $value, bool $allDay): ?Carbon
    {
        $dateTime = $value['dateTime'] ?? null;
        if (! is_string($dateTime) || trim($dateTime) === '') {
            return null;
        }

        if ($allDay) {
            return Carbon::parse($dateTime)->startOfDay();
        }

        return Carbon::parse($dateTime, (string) ($value['timeZone'] ?? 'UTC'))->utc();
    }

    private function validAccessToken(OutlookCalendarConnection $connection): string
    {
        if ($connection->access_token_encrypted && $connection->token_expires_at?->isFuture()) {
            return Crypt::decryptString($connection->access_token_encrypted);
        }
        if (! $connection->refresh_token_encrypted) {
            throw new RuntimeException('Outlook refresh token is missing.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => Crypt::decryptString($connection->refresh_token_encrypted),
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ]);
        if (! $response->successful()) {
            $this->markFailed($connection, 'Outlook token refresh failed.');
            throw new RuntimeException('Outlook token refresh failed.');
        }
        $this->storeTokenPayload($connection, $response->json());

        return Crypt::decryptString($connection->refresh()->access_token_encrypted);
    }

    private function storeTokenPayload(OutlookCalendarConnection $connection, array $payload): void
    {
        $updates = [
            'access_token_encrypted' => Crypt::encryptString((string) $payload['access_token']),
            'token_expires_at' => now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 3600) - 60)),
        ];
        if (! empty($payload['refresh_token'])) {
            $updates['refresh_token_encrypted'] = Crypt::encryptString((string) $payload['refresh_token']);
        }
        $connection->forceFill($updates)->save();
    }

    private function markFailed(OutlookCalendarConnection $connection, string $message): void
    {
        $connection->forceFill(['status' => 'error', 'last_error' => $message, 'last_error_at' => now()])->save();
    }

    private function assertConfigured(): void
    {
        if ($this->clientId() === '' || $this->clientSecret() === '') {
            throw new RuntimeException('Outlook calendar authorization is not configured.');
        }
    }

    private function clientId(): string
    {
        return (string) config('services.microsoft_outlook.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('services.microsoft_outlook.client_secret', '');
    }

    private function redirectUri(): string
    {
        return (string) config('services.microsoft_outlook.redirect_uri', url('/api/outlook-calendar/callback'));
    }
}
