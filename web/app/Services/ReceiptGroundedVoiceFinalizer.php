<?php

namespace App\Services;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;

final class ReceiptGroundedVoiceFinalizer
{
    /**
     * These are the complete deterministic voice-finalization allowlist and
     * templates. Anything outside this table requires Hermes composition.
     *
     * @var array<string, array{
     *     event_type:string,
     *     resource_id_key:string,
     *     event_data_keys:list<string>,
     *     template:string,
     *     requires_direct_id:bool
     * }>
     */
    private const DEFINITIONS = [
        'app.task.create' => [
            'event_type' => 'assistant.task.created',
            'resource_id_key' => 'task_id',
            'event_data_keys' => ['task_id', 'title'],
            'template' => 'Created task: %s.',
            'requires_direct_id' => false,
        ],
        'app.task.update' => [
            'event_type' => 'assistant.task.updated',
            'resource_id_key' => 'task_id',
            'event_data_keys' => ['task_id', 'title'],
            'template' => 'Updated task: %s.',
            'requires_direct_id' => true,
        ],
        'app.task.delete' => [
            'event_type' => 'assistant.task.deleted',
            'resource_id_key' => 'task_id',
            'event_data_keys' => ['task_id', 'title'],
            'template' => 'Deleted task: %s.',
            'requires_direct_id' => true,
        ],
        'app.reminder.create' => [
            'event_type' => 'assistant.reminder.created',
            'resource_id_key' => 'reminder_id',
            'event_data_keys' => ['reminder_id', 'title', 'remind_at'],
            'template' => 'Created reminder: %s.',
            'requires_direct_id' => false,
        ],
        'app.reminder.update' => [
            'event_type' => 'assistant.reminder.updated',
            'resource_id_key' => 'reminder_id',
            'event_data_keys' => ['reminder_id', 'title'],
            'template' => 'Updated reminder: %s.',
            'requires_direct_id' => true,
        ],
        'app.reminder.delete' => [
            'event_type' => 'assistant.reminder.deleted',
            'resource_id_key' => 'reminder_id',
            'event_data_keys' => ['reminder_id', 'title'],
            'template' => 'Deleted reminder: %s.',
            'requires_direct_id' => true,
        ],
        'app.calendar.create' => [
            'event_type' => 'assistant.calendar_event.created',
            'resource_id_key' => 'calendar_event_id',
            'event_data_keys' => ['calendar_event_id', 'title', 'starts_at', 'ends_at'],
            'template' => 'Created calendar event: %s.',
            'requires_direct_id' => false,
        ],
        'app.calendar.update' => [
            'event_type' => 'assistant.calendar_event.updated',
            'resource_id_key' => 'calendar_event_id',
            'event_data_keys' => ['calendar_event_id', 'title'],
            'template' => 'Updated calendar event: %s.',
            'requires_direct_id' => true,
        ],
        'app.calendar.delete' => [
            'event_type' => 'assistant.calendar_event.deleted',
            'resource_id_key' => 'calendar_event_id',
            'event_data_keys' => ['calendar_event_id', 'title'],
            'template' => 'Deleted calendar event: %s.',
            'requires_direct_id' => true,
        ],
        'app.note.create' => [
            'event_type' => 'assistant.note.created',
            'resource_id_key' => 'note_id',
            'event_data_keys' => ['note_id', 'title'],
            'template' => 'Created note: %s.',
            'requires_direct_id' => false,
        ],
        'app.note.update' => [
            'event_type' => 'assistant.note.updated',
            'resource_id_key' => 'note_id',
            'event_data_keys' => ['note_id', 'title'],
            'template' => 'Updated note: %s.',
            'requires_direct_id' => true,
        ],
        'app.note.delete' => [
            'event_type' => 'assistant.note.deleted',
            'resource_id_key' => 'note_id',
            'event_data_keys' => ['note_id', 'title'],
            'template' => 'Deleted note: %s.',
            'requires_direct_id' => true,
        ],
    ];

    /**
     * Return null whenever the receipt is not sufficient to make the narrow,
     * deterministic success claim. The caller must then use Hermes composition.
     *
     * @param  list<HermesSemanticOperationResult>  $results
     */
    public function finalize(
        HermesSemanticInterpretation $interpretation,
        array $results,
    ): ?HermesSemanticComposition {
        if ($interpretation->outcome !== HermesSemanticInterpretation::OUTCOME_EXECUTE
            || count($interpretation->operations) !== 1
            || ! array_is_list($results)
            || count($results) !== 1) {
            return null;
        }

        $operation = $interpretation->operations[0] ?? null;
        $result = $results[0] ?? null;
        if (! $operation instanceof HermesSemanticOperation
            || ! $result instanceof HermesSemanticOperationResult
            || $operation->dependencies !== []
            || array_key_exists('result_ref', $operation->arguments)
            || $result->operationId !== $operation->id
            || $result->tool !== $operation->tool
            || $result->status !== 'completed') {
            return null;
        }

        $definition = self::DEFINITIONS[$operation->tool] ?? null;
        if (! is_array($definition)
            || ! $this->sameKeys($result->data, ['changed', 'events', 'side_effect_committed'])
            || ($result->data['changed'] ?? null) !== true
            || ($result->data['side_effect_committed'] ?? null) !== true) {
            return null;
        }

        $events = $result->data['events'] ?? null;
        if (! is_array($events) || ! array_is_list($events) || count($events) !== 1) {
            return null;
        }

        $event = $events[0] ?? null;
        if (! is_array($event)
            || ! $this->sameKeys($event, ['id', 'type', 'status', 'data'])
            || ! is_int($event['id'] ?? null)
            || $event['id'] < 1
            || ($event['type'] ?? null) !== $definition['event_type']
            || ($event['status'] ?? null) !== 'succeeded') {
            return null;
        }

        $eventData = $event['data'] ?? null;
        if (! is_array($eventData)
            || ! $this->sameKeys($eventData, $definition['event_data_keys'])) {
            return null;
        }

        $resourceId = $eventData[$definition['resource_id_key']] ?? null;
        $title = $eventData['title'] ?? null;
        if (! is_int($resourceId)
            || $resourceId < 1
            || ! $this->safeTitle($title)
            || ! $this->operationMatchesReceipt($operation, $resourceId, $title, $definition['requires_direct_id'])
            || ! $this->supplementalReceiptFieldsAreValid($operation->tool, $eventData)) {
            return null;
        }

        return new HermesSemanticComposition(
            responseText: sprintf($definition['template'], $title),
            closeAfterResponse: false,
            responseExpected: false,
        );
    }

    /** @param list<string> $expected */
    private function sameKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function safeTitle(mixed $title): bool
    {
        return is_string($title)
            && $title !== ''
            && trim($title) === $title
            && preg_match('/[\x00-\x1F\x7F]/u', $title) !== 1;
    }

    private function operationMatchesReceipt(
        HermesSemanticOperation $operation,
        int $resourceId,
        string $title,
        bool $requiresDirectId,
    ): bool {
        $operationId = $operation->arguments['id'] ?? null;
        if ($requiresDirectId && (! is_int($operationId) || $operationId !== $resourceId)) {
            return false;
        }
        if (! $requiresDirectId && array_key_exists('id', $operation->arguments)) {
            return false;
        }

        return ! array_key_exists('title', $operation->arguments)
            || $operation->arguments['title'] === $title;
    }

    /** @param array<string, mixed> $eventData */
    private function supplementalReceiptFieldsAreValid(string $tool, array $eventData): bool
    {
        if ($tool === 'app.reminder.create') {
            return is_string($eventData['remind_at'] ?? null)
                && trim($eventData['remind_at']) !== '';
        }

        if ($tool === 'app.calendar.create') {
            $endsAt = $eventData['ends_at'] ?? null;

            return is_string($eventData['starts_at'] ?? null)
                && trim($eventData['starts_at']) !== ''
                && ($endsAt === null || (is_string($endsAt) && trim($endsAt) !== ''));
        }

        return true;
    }
}
