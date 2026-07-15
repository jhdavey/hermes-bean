<?php

namespace App\Data;

use InvalidArgumentException;
use JsonException;

final readonly class HermesSemanticOperation
{
    public const TOOLS = [
        'system.clock.read',
        'system.voice_state.read',
        'app.task.search',
        'app.reminder.search',
        'app.calendar.search',
        'app.note.search',
        'app.memory.search',
        'app.task.create',
        'app.task.update',
        'app.task.delete',
        'app.reminder.create',
        'app.reminder.update',
        'app.reminder.delete',
        'app.calendar.create',
        'app.calendar.update',
        'app.calendar.delete',
        'app.note.create',
        'app.note.update',
        'app.note.delete',
        'app.note_folder.create',
        'app.note_folder.update',
        'app.note_folder.delete',
        'app.event_category.create',
        'app.event_category.update',
        'app.event_category.delete',
        'app.blocker.create',
        'app.blocker.update',
        'app.blocker.resolve',
        'app.blocker.delete',
        'app.agent_profile.update',
        'app.conversation.update',
        'app.memory.create',
        'app.memory.update',
        'app.memory.delete',
        'app.history.search',
        'app.activity.search',
        'app.day.read',
        'external.lookup',
        'voice.playback.stop',
        'voice.work.status',
        'voice.work.cancel',
    ];

    private const FORBIDDEN_ARGUMENT_KEYS = [
        'authorization',
        'authorized',
        'deadline',
        'hard_deadline',
        'idempotency_key',
        'lifecycle_state',
        'queue',
        'queue_priority',
        'stable_turn_id',
        'subscription',
        'subscription_tier',
        'user_id',
        'voice_turn_id',
        'workspace_id',
    ];

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $dependencies
     */
    public function __construct(
        public string $id,
        public string $tool,
        public array $arguments,
        public array $dependencies = [],
    ) {
        if (trim($this->id) === '' || mb_strlen($this->id) > 100) {
            throw new InvalidArgumentException('A semantic operation requires a short, non-empty id.');
        }

        if (! in_array($this->tool, self::TOOLS, true)) {
            throw new InvalidArgumentException('The semantic operation selected an unsupported tool.');
        }

        foreach ($this->dependencies as $dependency) {
            if (! is_string($dependency) || trim($dependency) === '') {
                throw new InvalidArgumentException('Semantic operation dependencies must be non-empty operation ids.');
            }
        }

        if (count(array_unique($this->dependencies)) !== count($this->dependencies)) {
            throw new InvalidArgumentException('A semantic operation may not repeat a dependency.');
        }

        $this->assertApplicationOwnedFieldsAreAbsent($this->arguments);
    }

    /** @param array<string, mixed> $payload */
    public static function fromProviderPayload(array $payload): self
    {
        $argumentsJson = $payload['arguments_json'] ?? null;
        if (! is_string($argumentsJson)) {
            throw new InvalidArgumentException('Semantic operation arguments_json must be a JSON object string.');
        }

        $trimmedArguments = trim($argumentsJson);
        if (! str_starts_with($trimmedArguments, '{') || ! str_ends_with($trimmedArguments, '}')) {
            throw new InvalidArgumentException('Semantic operation arguments_json must encode an object.');
        }

        try {
            $arguments = json_decode($trimmedArguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Semantic operation arguments_json is invalid.', previous: $exception);
        }

        if (! is_array($arguments)) {
            throw new InvalidArgumentException('Semantic operation arguments_json must encode an object.');
        }

        $dependencies = $payload['dependencies'] ?? null;
        if (! is_array($dependencies) || ! array_is_list($dependencies)) {
            throw new InvalidArgumentException('Semantic operation dependencies must be a list.');
        }

        return new self(
            id: is_string($payload['id'] ?? null) ? $payload['id'] : '',
            tool: is_string($payload['tool'] ?? null) ? $payload['tool'] : '',
            arguments: $arguments,
            dependencies: $dependencies,
        );
    }

    /** @return array{id:string,tool:string,arguments:array<string,mixed>,dependencies:list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'dependencies' => $this->dependencies,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function assertApplicationOwnedFieldsAreAbsent(array $arguments): void
    {
        foreach ($arguments as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_ARGUMENT_KEYS, true)) {
                throw new InvalidArgumentException('Semantic operations may not set application-owned lifecycle or safety fields.');
            }

            if (is_array($value)) {
                $this->assertApplicationOwnedFieldsAreAbsent($value);
            }
        }
    }
}
