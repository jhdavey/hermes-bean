<?php

namespace Tests\Unit;

use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;
use App\Services\ReceiptGroundedVoiceFinalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReceiptGroundedVoiceFinalizerTest extends TestCase
{
    #[DataProvider('approvedCrudTemplates')]
    public function test_it_finalizes_exactly_the_approved_single_committed_crud_templates(
        string $tool,
        string $eventType,
        string $resourceIdKey,
        string $title,
        array $supplementalEventData,
        string $expectedResponse,
    ): void {
        $action = substr($tool, strrpos($tool, '.') + 1);
        $arguments = $action === 'create'
            ? ['title' => $title]
            : ['id' => 42];
        if ($tool === 'app.reminder.create') {
            $arguments['remind_at'] = $supplementalEventData['remind_at'];
        }
        if ($tool === 'app.calendar.create') {
            $arguments['starts_at'] = $supplementalEventData['starts_at'];
            $arguments['ends_at'] = $supplementalEventData['ends_at'];
        }

        $interpretation = $this->interpretation($tool, $arguments);
        $result = $this->operationResult(
            tool: $tool,
            data: $this->committedData(
                eventType: $eventType,
                eventData: [
                    $resourceIdKey => 42,
                    'title' => $title,
                    ...$supplementalEventData,
                ],
            ),
        );

        $composition = (new ReceiptGroundedVoiceFinalizer)->finalize($interpretation, [$result]);

        $this->assertNotNull($composition);
        $this->assertSame($expectedResponse, $composition->responseText);
        $this->assertFalse($composition->closeAfterResponse);
        $this->assertFalse($composition->responseExpected);
    }

    public static function approvedCrudTemplates(): array
    {
        return [
            'task create' => [
                'app.task.create', 'assistant.task.created', 'task_id', 'Buy milk', [],
                'Created task: Buy milk.',
            ],
            'task update' => [
                'app.task.update', 'assistant.task.updated', 'task_id', 'Buy oat milk', [],
                'Updated task: Buy oat milk.',
            ],
            'task delete' => [
                'app.task.delete', 'assistant.task.deleted', 'task_id', 'Buy oat milk', [],
                'Deleted task: Buy oat milk.',
            ],
            'reminder create' => [
                'app.reminder.create', 'assistant.reminder.created', 'reminder_id', 'Call Mom',
                ['remind_at' => '2026-07-16T13:00:00+00:00'],
                'Created reminder: Call Mom.',
            ],
            'reminder update' => [
                'app.reminder.update', 'assistant.reminder.updated', 'reminder_id', 'Call Dad', [],
                'Updated reminder: Call Dad.',
            ],
            'reminder delete' => [
                'app.reminder.delete', 'assistant.reminder.deleted', 'reminder_id', 'Call Dad', [],
                'Deleted reminder: Call Dad.',
            ],
            'calendar create' => [
                'app.calendar.create', 'assistant.calendar_event.created', 'calendar_event_id', 'Lunch',
                [
                    'starts_at' => '2026-07-20T16:00:00+00:00',
                    'ends_at' => '2026-07-20T17:00:00+00:00',
                ],
                'Created calendar event: Lunch.',
            ],
            'calendar update' => [
                'app.calendar.update', 'assistant.calendar_event.updated', 'calendar_event_id', 'Team lunch', [],
                'Updated calendar event: Team lunch.',
            ],
            'calendar delete' => [
                'app.calendar.delete', 'assistant.calendar_event.deleted', 'calendar_event_id', 'Team lunch', [],
                'Deleted calendar event: Team lunch.',
            ],
            'note create' => [
                'app.note.create', 'assistant.note.created', 'note_id', 'Trip ideas', [],
                'Created note: Trip ideas.',
            ],
            'note update' => [
                'app.note.update', 'assistant.note.updated', 'note_id', 'Summer trip ideas', [],
                'Updated note: Summer trip ideas.',
            ],
            'note delete' => [
                'app.note.delete', 'assistant.note.deleted', 'note_id', 'Summer trip ideas', [],
                'Deleted note: Summer trip ideas.',
            ],
        ];
    }

    public function test_it_rejects_multi_operation_ambiguous_partial_and_non_crud_work(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $search = new HermesSemanticOperation(
            id: 'find_task',
            tool: 'app.task.search',
            arguments: ['query' => 'Buy milk', 'match_mode' => 'exact_title', 'require_unique' => true],
        );
        $update = new HermesSemanticOperation(
            id: 'update_task',
            tool: 'app.task.update',
            arguments: ['result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id']],
            dependencies: ['find_task'],
        );
        $ambiguousPlan = $this->executeInterpretation([$search, $update]);
        $ambiguousResults = [
            new HermesSemanticOperationResult('find_task', 'app.task.search', 'completed', [
                'count' => 2,
                'ambiguous' => true,
            ]),
            $this->operationResult(operationId: 'update_task'),
        ];
        $this->assertNull($finalizer->finalize($ambiguousPlan, $ambiguousResults));

        $createTask = new HermesSemanticOperation('create_task', 'app.task.create', ['title' => 'Buy milk']);
        $createNote = new HermesSemanticOperation('create_note', 'app.note.create', ['title' => 'Shopping']);
        $partialPlan = $this->executeInterpretation([$createTask, $createNote]);
        $partialResults = [
            $this->operationResult(
                operationId: 'create_task',
                tool: 'app.task.create',
                data: $this->committedData('assistant.task.created', ['task_id' => 42, 'title' => 'Buy milk']),
            ),
            new HermesSemanticOperationResult('create_note', 'app.note.create', 'failed', [
                'side_effect_committed' => false,
                'category' => 'subscription_limit_reached',
            ]),
        ];
        $this->assertNull($finalizer->finalize($partialPlan, $partialResults));

        foreach (['external.lookup', 'app.task.search', 'app.memory.create', 'voice.playback.stop'] as $tool) {
            $interpretation = $this->interpretation($tool, []);
            $result = $this->operationResult(tool: $tool);
            $this->assertNull($finalizer->finalize($interpretation, [$result]), $tool);
        }
    }

    public function test_it_requires_exactly_one_matching_terminal_result(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $interpretation = $this->interpretation();
        $valid = $this->operationResult();

        $this->assertNull($finalizer->finalize($interpretation, []));
        $this->assertNull($finalizer->finalize($interpretation, [$valid, $valid]));
        $this->assertNull($finalizer->finalize($interpretation, ['receipt' => $valid]));
        $this->assertNull($finalizer->finalize(
            $interpretation,
            [$this->operationResult(operationId: 'different_operation')],
        ));
        $this->assertNull($finalizer->finalize(
            $interpretation,
            [$this->operationResult(tool: 'app.note.update')],
        ));
        foreach (['failed', 'canceled', 'skipped'] as $status) {
            $this->assertNull($finalizer->finalize(
                $interpretation,
                [$this->operationResult(status: $status)],
            ), $status);
        }
    }

    public function test_it_requires_an_authoritative_changed_and_committed_receipt(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $interpretation = $this->interpretation();
        $validData = $this->committedData();
        $cases = [
            'changed false' => [...$validData, 'changed' => false],
            'changed non-boolean' => [...$validData, 'changed' => 1],
            'commit false' => [...$validData, 'side_effect_committed' => false],
            'commit non-boolean' => [...$validData, 'side_effect_committed' => 1],
            'no events' => [...$validData, 'events' => []],
            'multiple events' => [...$validData, 'events' => [$validData['events'][0], $validData['events'][0]]],
            'associative events' => [...$validData, 'events' => ['event' => $validData['events'][0]]],
            'unexpected receipt fact' => [...$validData, 'ambiguous' => false],
        ];
        $missingCommit = $validData;
        unset($missingCommit['side_effect_committed']);
        $cases['missing commit'] = $missingCommit;
        $missingChanged = $validData;
        unset($missingChanged['changed']);
        $cases['missing changed'] = $missingChanged;

        foreach ($cases as $name => $data) {
            $this->assertNull(
                $finalizer->finalize($interpretation, [$this->operationResult(data: $data)]),
                $name,
            );
        }
    }

    public function test_it_requires_one_exact_canonical_success_event(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $interpretation = $this->interpretation();
        $validEvent = $this->committedData()['events'][0];
        $cases = [
            'event id missing' => array_diff_key($validEvent, ['id' => true]),
            'event id zero' => [...$validEvent, 'id' => 0],
            'event id string' => [...$validEvent, 'id' => '1'],
            'wrong event type' => [...$validEvent, 'type' => 'assistant.task.created'],
            'recorded is not committed success' => [...$validEvent, 'status' => 'recorded'],
            'failed event' => [...$validEvent, 'status' => 'failed'],
            'unexpected event field' => [...$validEvent, 'receipt' => true],
            'event data missing' => array_diff_key($validEvent, ['data' => true]),
        ];

        foreach ($cases as $name => $event) {
            $this->assertNull(
                $finalizer->finalize(
                    $interpretation,
                    [$this->operationResult(data: $this->committedData(events: [$event]))],
                ),
                $name,
            );
        }
    }

    public function test_it_requires_receipt_identity_and_safe_receipt_only_title(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $validEventData = ['task_id' => 42, 'title' => 'Buy milk'];
        $cases = [
            'resource id missing' => ['title' => 'Buy milk'],
            'resource id zero' => ['task_id' => 0, 'title' => 'Buy milk'],
            'resource id string' => ['task_id' => '42', 'title' => 'Buy milk'],
            'wrong resource id' => ['task_id' => 41, 'title' => 'Buy milk'],
            'title missing' => ['task_id' => 42],
            'title blank' => ['task_id' => 42, 'title' => ''],
            'title whitespace' => ['task_id' => 42, 'title' => ' Buy milk'],
            'title control character' => ['task_id' => 42, 'title' => "Buy\nmilk"],
            'title non-string' => ['task_id' => 42, 'title' => 123],
            'unexpected event fact' => [...$validEventData, 'ambiguous' => false],
        ];

        foreach ($cases as $name => $eventData) {
            $this->assertNull(
                $finalizer->finalize(
                    $this->interpretation(),
                    [$this->operationResult(data: $this->committedData(eventData: $eventData))],
                ),
                $name,
            );
        }

        $this->assertNull($finalizer->finalize(
            $this->interpretation(arguments: []),
            [$this->operationResult()],
        ));
        $this->assertNull($finalizer->finalize(
            $this->interpretation(arguments: ['id' => 41]),
            [$this->operationResult()],
        ));
        $this->assertNull($finalizer->finalize(
            $this->interpretation(arguments: ['id' => 42, 'title' => 'Different title']),
            [$this->operationResult()],
        ));
        $this->assertNull($finalizer->finalize(
            $this->interpretation(arguments: [
                'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
            ]),
            [$this->operationResult()],
        ));

        $createWithId = $this->interpretation('app.task.create', ['id' => 42, 'title' => 'Buy milk']);
        $createResult = $this->operationResult(
            tool: 'app.task.create',
            data: $this->committedData('assistant.task.created'),
        );
        $this->assertNull($finalizer->finalize($createWithId, [$createResult]));
    }

    public function test_it_rejects_incomplete_reminder_and_calendar_create_receipts(): void
    {
        $finalizer = new ReceiptGroundedVoiceFinalizer;
        $reminder = $this->interpretation('app.reminder.create', [
            'title' => 'Call Mom',
            'remind_at' => '2026-07-16T13:00:00+00:00',
        ]);
        foreach ([null, '', 123] as $remindAt) {
            $result = $this->operationResult(
                tool: 'app.reminder.create',
                data: $this->committedData('assistant.reminder.created', [
                    'reminder_id' => 42,
                    'title' => 'Call Mom',
                    'remind_at' => $remindAt,
                ]),
            );
            $this->assertNull($finalizer->finalize($reminder, [$result]));
        }

        $calendar = $this->interpretation('app.calendar.create', [
            'title' => 'Lunch',
            'starts_at' => '2026-07-20T16:00:00+00:00',
        ]);
        $invalidCalendarFields = [
            [null, null],
            ['', null],
            [123, null],
            ['2026-07-20T16:00:00+00:00', 123],
            ['2026-07-20T16:00:00+00:00', ''],
        ];
        foreach ($invalidCalendarFields as [$startsAt, $endsAt]) {
            $result = $this->operationResult(
                tool: 'app.calendar.create',
                data: $this->committedData('assistant.calendar_event.created', [
                    'calendar_event_id' => 42,
                    'title' => 'Lunch',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]),
            );
            $this->assertNull($finalizer->finalize($calendar, [$result]));
        }
    }

    /** @param list<HermesSemanticOperation> $operations */
    private function executeInterpretation(array $operations): HermesSemanticInterpretation
    {
        return new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
            responseText: null,
            clarificationQuestion: null,
            acknowledgementText: 'I’ll handle that.',
            closeAfterResponse: false,
            responseExpected: false,
            operations: $operations,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function interpretation(
        string $tool = 'app.task.update',
        array $arguments = ['id' => 42],
    ): HermesSemanticInterpretation {
        return $this->executeInterpretation([
            new HermesSemanticOperation(
                id: 'operation',
                tool: $tool,
                arguments: $arguments,
            ),
        ]);
    }

    /** @param array<string, mixed>|null $data */
    private function operationResult(
        string $operationId = 'operation',
        string $tool = 'app.task.update',
        string $status = 'completed',
        ?array $data = null,
    ): HermesSemanticOperationResult {
        return new HermesSemanticOperationResult(
            operationId: $operationId,
            tool: $tool,
            status: $status,
            data: $data ?? $this->committedData(),
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @param  list<array<string, mixed>>|null  $events
     * @return array<string, mixed>
     */
    private function committedData(
        string $eventType = 'assistant.task.updated',
        array $eventData = ['task_id' => 42, 'title' => 'Buy milk'],
        ?array $events = null,
    ): array {
        return [
            'changed' => true,
            'events' => $events ?? [[
                'id' => 100,
                'type' => $eventType,
                'status' => 'succeeded',
                'data' => $eventData,
            ]],
            'side_effect_committed' => true,
        ];
    }
}
