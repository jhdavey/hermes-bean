<?php

namespace App\Services\Bean;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

class BeanTimeContext
{
    /** @return array{timezone:string,timezone_source:string,now_utc:string,local_now:string,local_date:string} */
    public function forClientTimezone(?string $clientTimezone = null, string $source = 'browser'): array
    {
        $timezone = $this->normalizeTimezone($clientTimezone);
        $timezoneSource = $timezone !== null ? $source : 'app_default';
        $timezone ??= (string) config('app.timezone', 'UTC');

        return $this->snapshot($timezone, $timezoneSource);
    }

    /** @return array{timezone:string,timezone_source:string,now_utc:string,local_now:string,local_date:string} */
    public function forUser(User $user, ?string $fallbackClientTimezone = null): array
    {
        $timezone = $this->normalizeTimezone((string) ($user->timezone ?? ''));
        if ($timezone !== null) {
            return $this->snapshot($timezone, 'user');
        }

        $fallback = $this->normalizeTimezone($fallbackClientTimezone);
        if ($fallback !== null) {
            return $this->snapshot($fallback, 'browser');
        }

        return $this->forClientTimezone(null, 'app_default');
    }

    public function rememberUserTimezoneIfMissing(User $user, ?string $clientTimezone): ?string
    {
        $existing = $this->normalizeTimezone((string) ($user->timezone ?? ''));
        if ($existing !== null) {
            return $existing;
        }

        $timezone = $this->normalizeTimezone($clientTimezone);
        if ($timezone === null) {
            return null;
        }

        $user->forceFill(['timezone' => $timezone])->save();

        return $timezone;
    }

    /** @return array{timezone:string,timezone_source:string,now_utc:string,local_now:string,local_date:string} */
    public function forSession(BeanSession $session): array
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $clientTimezone = (string) data_get($metadata, 'client_timezone', '');
        if ($session->user) {
            return $this->forUser($session->user, $clientTimezone);
        }

        return $this->forClientTimezone($clientTimezone ?: null, 'browser');
    }

    /** @return array{timezone:string,timezone_source:string,now_utc:string,local_now:string,local_date:string} */
    public function forRun(BeanRun $run): array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $stored = data_get($metadata, 'time_context');
        if (is_array($stored)) {
            $timezone = $this->normalizeTimezone((string) ($stored['timezone'] ?? ''));
            if ($timezone !== null) {
                $nowUtc = trim((string) ($stored['now_utc'] ?? ''));
                $localNow = trim((string) ($stored['local_now'] ?? ''));
                $localDate = trim((string) ($stored['local_date'] ?? ''));
                if ($nowUtc !== '' && $localNow !== '' && $localDate !== '') {
                    return [
                        'timezone' => $timezone,
                        'timezone_source' => trim((string) ($stored['timezone_source'] ?? 'browser')) ?: 'browser',
                        'now_utc' => $nowUtc,
                        'local_now' => $localNow,
                        'local_date' => $localDate,
                    ];
                }
            }
        }

        if ($run->user) {
            return $this->forUser($run->user, (string) data_get($metadata, 'client_timezone', ''));
        }

        return $run->session ? $this->forSession($run->session) : $this->forClientTimezone(null, 'app_default');
    }

    public function normalizeTimezone(?string $timezone): ?string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return null;
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{timezone:string} $context */
    public function timezone(array $context): string
    {
        return $this->normalizeTimezone((string) ($context['timezone'] ?? '')) ?: (string) config('app.timezone', 'UTC');
    }

    /** @param array{timezone:string} $context */
    public function localNow(array $context): Carbon
    {
        return Carbon::parse((string) ($context['now_utc'] ?? now('UTC')->toIso8601String()), 'UTC')->timezone($this->timezone($context));
    }

    /** @param array{timezone:string} $context */
    public function utcNow(array $context): Carbon
    {
        return Carbon::parse((string) ($context['now_utc'] ?? now('UTC')->toIso8601String()), 'UTC')->utc();
    }

    /** @param array{timezone:string} $context */
    public function localDayUtcRange(string $date, array $context): array
    {
        $timezone = $this->timezone($context);
        $start = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($date, $timezone)->endOfDay()->utc();

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    /** @param array{timezone:string} $context */
    public function todayUtcRange(array $context): array
    {
        return $this->localDayUtcRange($this->localNow($context)->toDateString(), $context);
    }

    /** @param array{timezone:string} $context */
    public function tomorrowUtcRange(array $context): array
    {
        return $this->localDayUtcRange($this->localNow($context)->addDay()->toDateString(), $context);
    }

    /** @param array{timezone:string} $context */
    public function parseUserDateTime(mixed $value, array $context): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        if ($this->isDateOnly($text)) {
            return Carbon::parse($text, $this->timezone($context))->startOfDay()->utc();
        }

        if ($this->hasExplicitTimezone($text)) {
            return Carbon::parse($text)->utc();
        }

        return Carbon::parse($text, $this->timezone($context))->utc();
    }

    public function isDateOnly(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private function hasExplicitTimezone(string $value): bool
    {
        $text = trim($value);

        return preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $text) === 1
            || preg_match('/\b(?:UTC|GMT|[A-Za-z_]+\/[A-Za-z_]+)\b/', $text) === 1;
    }

    private function snapshot(string $timezone, string $source): array
    {
        $nowUtc = now('UTC');
        $localNow = $nowUtc->copy()->timezone($timezone);

        return [
            'timezone' => $timezone,
            'timezone_source' => $source,
            'now_utc' => $nowUtc->toIso8601String(),
            'local_now' => $localNow->toIso8601String(),
            'local_date' => $localNow->toDateString(),
        ];
    }
}
