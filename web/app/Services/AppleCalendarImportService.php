<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AppleCalendarImportService
{
    private const MAX_ICS_BYTES = 5_000_000;

    public function __construct(
        private readonly PlanLimitService $planLimits,
    ) {}

    public function providerPresets(): array
    {
        return [
            [
                'key' => 'apple',
                'label' => 'Apple Calendar',
                'description' => 'Paste an iCloud public calendar link from Apple Calendar.',
                'link_label' => 'iCloud public calendar link',
                'link_hint' => 'webcal://pXX-caldav.icloud.com/published/2/...',
                'instructions' => [
                    'In Apple Calendar or iCloud.com, turn on Public Calendar for the calendar you want.',
                    'Copy the generated webcal link.',
                    'Paste the link here to import events into this workspace.',
                ],
            ],
            [
                'key' => 'google',
                'label' => 'Google Calendar',
                'description' => 'Paste a Google secret iCal address for a one-time import.',
                'link_label' => 'Google secret iCal address',
                'link_hint' => 'https://calendar.google.com/calendar/ical/...',
                'instructions' => [
                    'Open Google Calendar settings for the calendar.',
                    'Copy the Secret address in iCal format.',
                    'Paste it here for a one-time import. Use Google sync for ongoing connected sync.',
                ],
            ],
            [
                'key' => 'outlook',
                'label' => 'Outlook Calendar',
                'description' => 'Paste an Outlook published ICS link for a one-time import.',
                'link_label' => 'Outlook published ICS link',
                'link_hint' => 'https://outlook.live.com/owa/calendar/.../calendar.ics',
                'instructions' => [
                    'Publish the Outlook calendar as an ICS link.',
                    'Copy the ICS link.',
                    'Paste it here for a one-time import. Use Outlook sync for ongoing connected sync.',
                ],
            ],
            [
                'key' => 'proton',
                'label' => 'Proton Calendar',
                'description' => 'Paste a Proton share link for calendars shared with anyone.',
                'link_label' => 'Proton calendar share link',
                'link_hint' => 'https://calendar.proton.me/api/calendar/v1/url/...',
                'instructions' => [
                    'In Proton Calendar, share the calendar with anyone.',
                    'Copy the generated calendar link.',
                    'Paste it here to import the visible events.',
                ],
            ],
            [
                'key' => 'yahoo',
                'label' => 'Yahoo Calendar',
                'description' => 'Paste a Yahoo iCal link or exported calendar URL.',
                'link_label' => 'Yahoo iCal link',
                'link_hint' => 'https://calendar.yahoo.com/.../calendar.ics',
                'instructions' => [
                    'Open Yahoo Calendar settings for the calendar.',
                    'Copy the iCal link or export link.',
                    'Paste it here to import the events.',
                ],
            ],
            [
                'key' => 'fastmail',
                'label' => 'Fastmail',
                'description' => 'Paste a Fastmail calendar sharing link.',
                'link_label' => 'Fastmail calendar link',
                'link_hint' => 'https://calendar.fastmail.com/.../calendar.ics',
                'instructions' => [
                    'Share or publish the Fastmail calendar to get an iCalendar link.',
                    'Copy the generated link.',
                    'Paste it here for a one-time import.',
                ],
            ],
            [
                'key' => 'nextcloud',
                'label' => 'Nextcloud',
                'description' => 'Paste a public Nextcloud/ownCloud calendar subscription link.',
                'link_label' => 'Nextcloud public calendar link',
                'link_hint' => 'https://cloud.example.com/remote.php/dav/public-calendars/...',
                'instructions' => [
                    'In Nextcloud Calendar, copy the public subscription link.',
                    'Use the webcal or download link for the calendar.',
                    'Paste it here to import events into HeyBean.',
                ],
            ],
            [
                'key' => 'ics',
                'label' => 'Other iCal link',
                'description' => 'Use any public .ics or webcal calendar URL.',
                'link_label' => 'Public iCal or webcal link',
                'link_hint' => 'webcal://example.com/calendar.ics',
                'instructions' => [
                    'Copy a public .ics, iCal, or webcal calendar URL.',
                    'Paste it here to import events.',
                    'Only use links you trust and intend to import into this workspace.',
                ],
            ],
        ];
    }

    public function importFromUrl(User $user, Workspace $workspace, string $url, string $providerKey = 'ics'): array
    {
        $providerKey = $this->normalizeProviderKey($providerKey);
        $providerLabel = $this->providerLabel($providerKey);
        $url = $this->normalizeCalendarUrl($url, $providerLabel);
        $response = Http::timeout(15)
            ->accept('text/calendar, text/plain, */*')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException($providerLabel.' import failed.');
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_ICS_BYTES) {
            throw new RuntimeException($providerLabel.' import returned an invalid calendar file.');
        }

        return $this->importIcs($user, $workspace, $body, $url, $providerKey);
    }

    public function importIcs(User $user, Workspace $workspace, string $ics, ?string $sourceUrl = null, string $providerKey = 'ics'): array
    {
        $providerKey = $this->normalizeProviderKey($providerKey);
        $events = $this->parseEvents($ics);
        if ($events === []) {
            throw new RuntimeException('No calendar events were found in that external calendar.');
        }

        $imported = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $host = $sourceUrl ? parse_url($sourceUrl, PHP_URL_HOST) : null;

        foreach ($events as $event) {
            $uid = trim((string) ($event['UID']['value'] ?? ''));
            $startsAt = $this->parseDateTime($event['DTSTART'] ?? null);

            if (! $startsAt) {
                $skipped++;

                continue;
            }

            $existing = $uid !== ''
                ? $this->existingExternalEvent($workspace, $providerKey, $uid)
                : new CalendarEvent;

            $providerStatus = $this->calendarStatus($event);
            if ($providerStatus === 'cancelled') {
                if ($existing->exists) {
                    $existing->delete();
                    $deleted++;
                } else {
                    $skipped++;
                }

                continue;
            }

            $endsAt = $this->parseDateTime($event['DTEND'] ?? null) ?? $startsAt;
            $isAllDay = $startsAt['all_day'] || ($endsAt['all_day'] ?? false);
            $rrule = $event['RRULE']['value'] ?? null;
            $recurrence = $this->mappedRecurrence($rrule);
            if ($recurrence !== null && ! $this->planLimits->canUseRecurringCalendar($user)) {
                $recurrence = null;
            }
            $lastModified = $this->parseDateTime($event['LAST-MODIFIED'] ?? null);

            $metadata = array_merge($existing->exists ? ($existing->metadata ?? []) : [], [
                'source' => $providerKey === 'apple' ? 'apple_calendar' : 'external_calendar',
                'external_calendar_provider' => $providerKey,
                'external_calendar_status' => $providerStatus,
                'apple_calendar_uid' => $uid !== '' ? $uid : null,
                'external_calendar_uid' => $uid !== '' ? $uid : null,
                'apple_calendar_sequence' => $event['SEQUENCE']['value'] ?? null,
                'external_calendar_sequence' => $event['SEQUENCE']['value'] ?? null,
                'apple_calendar_source_host' => $host,
                'external_calendar_source_host' => $host,
                'apple_calendar_last_modified' => $lastModified ? $lastModified['value']->toIso8601String() : null,
                'external_calendar_last_modified' => $lastModified ? $lastModified['value']->toIso8601String() : null,
                'all_day' => $isAllDay,
                'ical_rrule' => is_string($rrule) && trim($rrule) !== '' ? trim($rrule) : null,
                'last_imported_from_apple_at' => now()->toIso8601String(),
                'last_imported_from_external_calendar_at' => now()->toIso8601String(),
            ]);

            $existing->forceFill([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'created_by_user_id' => $existing->created_by_user_id ?: $user->id,
                'title' => $this->textValue($event['SUMMARY'] ?? null) ?: 'Untitled calendar event',
                'description' => $this->textValue($event['DESCRIPTION'] ?? null),
                'location' => $this->textValue($event['LOCATION'] ?? null),
                'category' => $existing->category ?: $this->providerLabel($providerKey),
                'color' => $existing->color ?: $this->providerColor($providerKey),
                'recurrence' => $recurrence,
                'starts_at' => $startsAt['value'],
                'ends_at' => $endsAt['value'],
                'status' => 'scheduled',
                'metadata' => $metadata,
            ])->save();

            $existing->wasRecentlyCreated ? $imported++ : $updated++;
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'total' => count($events),
            'workspace_id' => (int) $workspace->id,
            'provider_key' => $providerKey,
            'provider_label' => $this->providerLabel($providerKey),
        ];
    }

    private function normalizeProviderKey(string $providerKey): string
    {
        $providerKey = strtolower(trim($providerKey));
        $valid = array_column($this->providerPresets(), 'key');

        return in_array($providerKey, $valid, true) ? $providerKey : 'ics';
    }

    private function providerLabel(string $providerKey): string
    {
        foreach ($this->providerPresets() as $provider) {
            if ($provider['key'] === $providerKey) {
                return $provider['label'];
            }
        }

        return 'External Calendar';
    }

    private function providerColor(string $providerKey): string
    {
        return match ($providerKey) {
            'apple' => '#007AFF',
            'google' => '#4285F4',
            'outlook' => '#2563EB',
            'proton' => '#6D4AFF',
            'yahoo' => '#6001D2',
            'fastmail' => '#0EA5E9',
            'nextcloud' => '#0082C9',
            default => '#34C759',
        };
    }

    private function normalizeCalendarUrl(string $url, string $providerLabel): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException($providerLabel.' URL is required.');
        }

        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://'.substr($url, strlen('webcal://'));
        } elseif (str_starts_with(strtolower($url), 'webcals://')) {
            $url = 'https://'.substr($url, strlen('webcals://'));
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isAllowedPublicHost($host)) {
            throw new RuntimeException($providerLabel.' URL must be a public webcal or HTTPS link.');
        }

        return $url;
    }

    private function isAllowedPublicHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * @return array<int, array<string, array{value: string, params: array<string, string>}>>
     */
    private function parseEvents(string $ics): array
    {
        $lines = $this->unfoldLines($ics);
        $events = [];
        $current = null;
        $inEvent = false;

        foreach ($lines as $line) {
            $property = $this->parseProperty($line);
            if (! $property) {
                continue;
            }

            [$name, $value, $params] = $property;
            if ($name === 'BEGIN' && strtoupper($value) === 'VEVENT') {
                $current = [];
                $inEvent = true;

                continue;
            }
            if ($name === 'END' && strtoupper($value) === 'VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;
                $inEvent = false;

                continue;
            }
            if (! $inEvent || $current === null) {
                continue;
            }

            $current[$name] = ['value' => $value, 'params' => $params];
        }

        return $events;
    }

    /**
     * @return array<int, string>
     */
    private function unfoldLines(string $ics): array
    {
        $rawLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $ics));
        $lines = [];

        foreach ($rawLines as $line) {
            $line = rtrim($line, "\n");
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($lines !== []) {
                    $lines[array_key_last($lines)] .= substr($line, 1);
                }

                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array{string, string, array<string, string>}|null
     */
    private function parseProperty(string $line): ?array
    {
        $separator = strpos($line, ':');
        if ($separator === false) {
            return null;
        }

        $head = substr($line, 0, $separator);
        $value = substr($line, $separator + 1);
        $parts = explode(';', $head);
        $name = strtoupper(array_shift($parts) ?: '');
        if ($name === '') {
            return null;
        }

        $params = [];
        foreach ($parts as $part) {
            [$key, $paramValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = strtoupper(trim($key));
            if ($key !== '') {
                $params[$key] = trim($paramValue, '"');
            }
        }

        return [$name, $value, $params];
    }

    /**
     * @param  array{value: string, params: array<string, string>}|null  $property
     * @return array{value: Carbon, all_day: bool}|null
     */
    private function parseDateTime(?array $property): ?array
    {
        if (! $property) {
            return null;
        }

        $raw = trim($property['value']);
        if ($raw === '') {
            return null;
        }

        $params = $property['params'];
        $allDay = strtoupper((string) ($params['VALUE'] ?? '')) === 'DATE' || preg_match('/^\d{8}$/', $raw);
        $timezone = $this->validTimezone((string) ($params['TZID'] ?? '')) ?: config('app.timezone', 'UTC');

        try {
            if ($allDay) {
                return [
                    'value' => Carbon::createFromFormat('Ymd', substr($raw, 0, 8), $timezone)->startOfDay(),
                    'all_day' => true,
                ];
            }

            if (str_ends_with($raw, 'Z')) {
                $value = Carbon::createFromFormat('Ymd\THis\Z', $raw, 'UTC')->utc();
            } else {
                $format = strlen($raw) === 13 ? 'Ymd\THi' : 'Ymd\THis';
                $value = Carbon::createFromFormat($format, $raw, $timezone)->utc();
            }

            return ['value' => $value, 'all_day' => false];
        } catch (\Throwable) {
            try {
                return ['value' => Carbon::parse($raw, $timezone)->utc(), 'all_day' => false];
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function validTimezone(string $timezone): ?string
    {
        if ($timezone === '') {
            return null;
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{value: string, params: array<string, string>}|null  $property
     */
    private function textValue(?array $property): ?string
    {
        if (! $property) {
            return null;
        }

        $value = str_replace(['\\n', '\\N'], "\n", $property['value']);
        $value = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $value);
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function calendarStatus(array $event): string
    {
        $status = strtolower(trim((string) ($event['STATUS']['value'] ?? 'confirmed')));

        return $status === '' ? 'confirmed' : $status;
    }

    private function mappedRecurrence(?string $rrule): ?string
    {
        if (! is_string($rrule) || trim($rrule) === '') {
            return null;
        }

        $parts = collect(explode(';', strtoupper($rrule)))
            ->mapWithKeys(function (string $part): array {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

                return [trim($key) => trim($value)];
            });

        if (($parts['INTERVAL'] ?? '1') !== '1' || $parts->has('BYDAY') || $parts->has('BYMONTHDAY')) {
            return null;
        }

        return match ($parts['FREQ'] ?? null) {
            'DAILY' => 'daily',
            'WEEKLY' => 'weekly',
            'MONTHLY' => 'monthly',
            'YEARLY' => 'yearly',
            default => null,
        };
    }

    private function existingExternalEvent(Workspace $workspace, string $providerKey, string $uid): CalendarEvent
    {
        return CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($providerKey): void {
                $query->where('metadata->external_calendar_provider', $providerKey);
                if ($providerKey === 'apple') {
                    $query->orWhere(function ($query): void {
                        $query->where('metadata->source', 'apple_calendar')
                            ->where(function ($query): void {
                                $query->where('metadata->external_calendar_provider', 'apple')
                                    ->orWhereNull('metadata->external_calendar_provider');
                            });
                    });
                }
            })
            ->where(function ($query) use ($uid): void {
                $query->where('metadata->external_calendar_uid', $uid)
                    ->orWhere('metadata->apple_calendar_uid', $uid);
            })
            ->first() ?? new CalendarEvent;
    }
}
