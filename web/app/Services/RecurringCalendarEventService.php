<?php

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Support\Collection;

class RecurringCalendarEventService
{
    private const GENERATED_METADATA_KEY = 'recurrence_generated';
    private const PARENT_METADATA_KEY = 'recurrence_parent_event_id';
    private const OCCURRENCE_DATE_METADATA_KEY = 'recurrence_occurrence_date';
    private const MATERIALIZED_UNTIL_METADATA_KEY = 'recurrence_materialized_until';

    public function materializeAll(?CarbonInterface $horizon = null): int
    {
        $created = 0;
        $horizon ??= CarbonImmutable::now('UTC')->addYear()->endOfDay();

        CalendarEvent::query()
            ->whereNotNull('recurrence')
            ->orderBy('id')
            ->chunkById(100, function (Collection $events) use (&$created, $horizon): void {
                foreach ($events as $event) {
                    if ($this->isGeneratedOccurrence($event)) {
                        continue;
                    }
                    $created += $this->materialize($event, $horizon);
                }
            });

        return $created;
    }

    public function refreshMaterializedOccurrences(CalendarEvent $event, ?CarbonInterface $horizon = null): int
    {
        if ($this->isGeneratedOccurrence($event)) {
            return 0;
        }

        $this->deleteGeneratedOccurrences($event);

        return $this->materialize($event, $horizon);
    }

    public function materialize(CalendarEvent $event, ?CarbonInterface $horizon = null): int
    {
        if (! $this->isRecurringSource($event)) {
            $this->deleteGeneratedOccurrences($event);

            return 0;
        }

        $metadata = $event->metadata ?? [];
        $startsAt = CarbonImmutable::parse($event->starts_at)->utc();
        $endsAt = $event->ends_at ? CarbonImmutable::parse($event->ends_at)->utc() : null;
        $duration = $endsAt ? $startsAt->diffAsCarbonInterval($endsAt) : null;
        $horizon ??= CarbonImmutable::now('UTC')->addYear()->endOfDay();
        $horizon = CarbonImmutable::parse($horizon)->utc()->endOfDay();

        $recurrenceUntil = $metadata['recurrence_until'] ?? null;
        if (is_string($recurrenceUntil) && trim($recurrenceUntil) !== '') {
            $until = CarbonImmutable::parse($recurrenceUntil, 'UTC')->endOfDay();
            if ($until->lt($horizon)) {
                $horizon = $until;
            }
        }

        if ($horizon->lte($startsAt)) {
            return 0;
        }

        $existingOccurrenceDates = $this->generatedOccurrences($event)
            ->map(fn (CalendarEvent $occurrence): ?string => $this->occurrenceDate($occurrence))
            ->filter()
            ->unique()
            ->flip();
        $exceptionDates = collect($metadata['recurring_exception_dates'] ?? $metadata['recurrence_exceptions'] ?? [])
            ->map(fn ($date): string => trim((string) $date))
            ->filter()
            ->unique()
            ->flip();

        $created = 0;
        foreach ($this->occurrenceStarts($event, $horizon) as $occurrenceStart) {
            $occurrenceDate = $occurrenceStart->toDateString();
            if ($occurrenceStart->equalTo($startsAt) || $existingOccurrenceDates->has($occurrenceDate) || $exceptionDates->has($occurrenceDate)) {
                continue;
            }

            CalendarEvent::create([
                'user_id' => $event->user_id,
                'workspace_id' => $event->workspace_id,
                'created_by_user_id' => $event->created_by_user_id,
                'conversation_session_id' => $event->conversation_session_id,
                'title' => $event->title,
                'description' => $event->description,
                'location' => $event->location,
                'category' => $event->category,
                'color' => $event->color,
                'is_critical' => $event->is_critical,
                'recurrence' => null,
                'starts_at' => $occurrenceStart,
                'ends_at' => $duration ? $occurrenceStart->add($duration) : null,
                'status' => $event->status,
                'metadata' => $this->generatedMetadata($metadata, $event, $occurrenceDate),
            ]);
            $existingOccurrenceDates->put($occurrenceDate, true);
            $created++;
        }

        $metadata[self::MATERIALIZED_UNTIL_METADATA_KEY] = $horizon->toDateString();
        $event->forceFill(['metadata' => $metadata])->save();

        return $created;
    }

    public function deleteGeneratedOccurrences(CalendarEvent $event): int
    {
        $ids = $this->generatedOccurrences($event)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        return CalendarEvent::query()->whereIn('id', $ids)->delete();
    }

    public function deleteGeneratedOccurrence(CalendarEvent $event, string $occurrenceDate): int
    {
        $ids = $this->generatedOccurrences($event)
            ->filter(fn (CalendarEvent $occurrence): bool => $this->occurrenceDate($occurrence) === $occurrenceDate)
            ->pluck('id')
            ->all();

        return $ids === [] ? 0 : CalendarEvent::query()->whereIn('id', $ids)->delete();
    }

    public function deleteGeneratedOccurrencesFrom(CalendarEvent $event, string $occurrenceDate): int
    {
        $ids = $this->generatedOccurrences($event)
            ->filter(function (CalendarEvent $occurrence) use ($occurrenceDate): bool {
                $date = $this->occurrenceDate($occurrence);

                return $date !== null && $date >= $occurrenceDate;
            })
            ->pluck('id')
            ->all();

        return $ids === [] ? 0 : CalendarEvent::query()->whereIn('id', $ids)->delete();
    }

    private function isRecurringSource(CalendarEvent $event): bool
    {
        $recurrence = strtolower(trim((string) ($event->recurrence ?? 'none')));

        return $recurrence !== '' && $recurrence !== 'none' && ! $this->isGeneratedOccurrence($event);
    }

    private function isGeneratedOccurrence(CalendarEvent $event): bool
    {
        $metadata = $event->metadata ?? [];

        return (bool) ($metadata[self::GENERATED_METADATA_KEY] ?? false)
            || filled($metadata[self::PARENT_METADATA_KEY] ?? null);
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    private function generatedOccurrences(CalendarEvent $event): Collection
    {
        return CalendarEvent::query()
            ->where('workspace_id', $event->workspace_id)
            ->where('user_id', $event->user_id)
            ->orderBy('starts_at')
            ->get()
            ->filter(function (CalendarEvent $candidate) use ($event): bool {
                $metadata = $candidate->metadata ?? [];

                return (string) ($metadata[self::PARENT_METADATA_KEY] ?? '') === (string) $event->id;
            })
            ->values();
    }

    private function occurrenceDate(CalendarEvent $event): ?string
    {
        $metadata = $event->metadata ?? [];
        $date = $metadata[self::OCCURRENCE_DATE_METADATA_KEY] ?? null;

        return is_string($date) && trim($date) !== ''
            ? trim($date)
            : ($event->starts_at ? CarbonImmutable::parse($event->starts_at)->utc()->toDateString() : null);
    }

    /**
     * @return iterable<int, CarbonImmutable>
     */
    private function occurrenceStarts(CalendarEvent $event, CarbonImmutable $horizon): iterable
    {
        $startsAt = CarbonImmutable::parse($event->starts_at)->utc();
        $recurrence = strtolower(trim((string) $event->recurrence));
        $metadata = $event->metadata ?? [];

        if ($recurrence === 'specific_days') {
            $days = collect($metadata['days'] ?? $metadata['specific_days'] ?? $metadata['specificDays'] ?? [])
                ->map(fn ($day): string => strtolower(substr(trim((string) $day), 0, 3)))
                ->filter()
                ->unique()
                ->values();
            if ($days->isEmpty()) {
                return;
            }
            $cursor = $startsAt->addDay();
            while ($cursor->lte($horizon)) {
                if ($days->contains($cursor->format('D') === 'Thu' ? 'thu' : strtolower($cursor->format('D')))) {
                    yield $cursor;
                }
                $cursor = $cursor->addDay();
            }

            return;
        }

        $cursor = $this->nextOccurrenceStart($startsAt, $recurrence, $metadata);
        while ($cursor && $cursor->lte($horizon)) {
            yield $cursor;
            $cursor = $this->nextOccurrenceStart($cursor, $recurrence, $metadata);
        }
    }

    private function nextOccurrenceStart(CarbonImmutable $from, string $recurrence, array $metadata): ?CarbonImmutable
    {
        return match ($recurrence) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            'yearly' => $from->addYearNoOverflow(),
            'interval' => $this->addInterval($from, $metadata),
            default => null,
        };
    }

    private function addInterval(CarbonImmutable $from, array $metadata): CarbonImmutable
    {
        $interval = max(1, (int) ($metadata['interval'] ?? 1));
        $unit = strtolower((string) ($metadata['unit'] ?? $metadata['interval_unit'] ?? $metadata['intervalUnit'] ?? 'days'));

        return match ($unit) {
            'weeks', 'week' => $from->addWeeks($interval),
            'months', 'month' => $from->addMonthsNoOverflow($interval),
            default => $from->addDays($interval),
        };
    }

    private function generatedMetadata(array $sourceMetadata, CalendarEvent $event, string $occurrenceDate): array
    {
        unset(
            $sourceMetadata['google_calendar_id'],
            $sourceMetadata['google_calendar_ids'],
            $sourceMetadata[self::MATERIALIZED_UNTIL_METADATA_KEY]
        );

        return $sourceMetadata + [
            self::GENERATED_METADATA_KEY => true,
            self::PARENT_METADATA_KEY => $event->id,
            self::OCCURRENCE_DATE_METADATA_KEY => $occurrenceDate,
        ];
    }
}
