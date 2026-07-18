<?php

namespace App\Services\Domain;

use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;

class DomainResourceCatalog
{
    /**
     * @var array<string, array{class: class-string, aliases: list<string>, statuses?: list<string>, active_status?: string, temporal_field?: string, filterable_fields: list<string>}>
     */
    private const RESOURCES = [
        'tasks' => [
            'class' => Task::class,
            'aliases' => ['task', 'tasks', 'todo', 'todos', 'to-do', 'to-dos'],
            'statuses' => ['open', 'completed'],
            'active_status' => 'open',
            'temporal_field' => 'due_at',
            'filterable_fields' => ['id', 'title', 'type', 'status', 'category', 'due_at', 'completed_at', 'created_at', 'updated_at'],
        ],
        'reminders' => [
            'class' => Reminder::class,
            'aliases' => ['reminder', 'reminders'],
            'statuses' => ['scheduled', 'completed'],
            'active_status' => 'scheduled',
            'temporal_field' => 'remind_at',
            'filterable_fields' => ['id', 'title', 'status', 'category', 'remind_at', 'created_at', 'updated_at'],
        ],
        'calendar_events' => [
            'class' => CalendarEvent::class,
            'aliases' => ['calendar', 'calendar_event', 'calendar_events', 'event', 'events', 'appointment', 'appointments'],
            'statuses' => ['scheduled', 'cancelled'],
            'active_status' => 'scheduled',
            'temporal_field' => 'starts_at',
            'filterable_fields' => ['id', 'title', 'status', 'category', 'starts_at', 'ends_at', 'created_at', 'updated_at'],
        ],
        'notes' => [
            'class' => Note::class,
            'aliases' => ['note', 'notes'],
            'temporal_field' => 'updated_at',
            'filterable_fields' => ['id', 'title', 'plain_text', 'is_pinned', 'created_at', 'updated_at'],
        ],
    ];

    public function classForResource(string $resource): ?string
    {
        $resource = strtolower(trim($resource));
        foreach (self::RESOURCES as $name => $schema) {
            if ($resource === $name || in_array($resource, $schema['aliases'], true)) {
                return $schema['class'];
            }
        }

        return null;
    }

    public function resourceForClass(string $class): ?string
    {
        foreach (self::RESOURCES as $name => $schema) {
            if ($schema['class'] === $class) {
                return $name;
            }
        }

        return null;
    }

    public function statusesFor(string $resource): array
    {
        $class = $this->classForResource($resource);
        if ($class === null) return [];

        return $this->schemaForClass($class)['statuses'] ?? [];
    }

    public function activeStatusForClass(string $class): ?string
    {
        return $this->schemaForClass($class)['active_status'] ?? null;
    }

    public function temporalFieldForClass(string $class): ?string
    {
        return $this->schemaForClass($class)['temporal_field'] ?? null;
    }

    public function filterableFieldsForClass(string $class): array
    {
        return $this->schemaForClass($class)['filterable_fields'] ?? ['id', 'title', 'created_at', 'updated_at'];
    }

    public function normalizeStatusForClass(string $class, mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));
        if ($status === '') return null;

        $statuses = $this->schemaForClass($class)['statuses'] ?? [];
        if (in_array($status, $statuses, true)) {
            return $status;
        }

        return match ($class) {
            Task::class => in_array($status, ['active', 'incomplete', 'not_completed', 'not completed', 'not complete', 'pending', 'overdue'], true) ? 'open' : null,
            Reminder::class => in_array($status, ['active', 'pending', 'incomplete', 'not_completed', 'not completed', 'not complete', 'overdue'], true) ? 'scheduled' : null,
            CalendarEvent::class => in_array($status, ['active', 'pending', 'upcoming'], true) ? 'scheduled' : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForClass(string $class): array
    {
        foreach (self::RESOURCES as $schema) {
            if ($schema['class'] === $class) {
                return $schema;
            }
        }

        return [];
    }
}
