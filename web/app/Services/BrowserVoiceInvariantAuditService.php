<?php

namespace App\Services;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnState;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Collection;

class BrowserVoiceInvariantAuditService
{
    private const MAX_VIOLATION_SAMPLES = 200;

    private const ACTIVE_RUN_STATUSES = ['queued', 'running', 'finalizing'];

    private const TERMINAL_RUN_STATUSES = ['completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   audited_at: string,
     *   counts: array<string, int>,
     *   violations_by_code: array<string, int>,
     *   violation_samples: array<int, array<string, int|string|null>>,
     *   samples_truncated: bool
     * }
     */
    public function audit(int $chunkSize = 200): array
    {
        $chunkSize = max(1, min(500, $chunkSize));
        $report = [
            'status' => 'pass',
            'audited_at' => now()->toIso8601String(),
            'counts' => [
                'turns' => 0,
                'messages' => 0,
                'runs' => 0,
                'events' => 0,
                'violations' => 0,
            ],
            'violations_by_code' => [],
            'violation_samples' => [],
            'samples_truncated' => false,
        ];
        $violation = function (string $code, string $message, ?VoiceTurn $turn = null, ?AssistantRun $run = null, ?VoiceTurnEvent $event = null) use (&$report): void {
            $report['counts']['violations']++;
            $report['violations_by_code'][$code] = ($report['violations_by_code'][$code] ?? 0) + 1;
            if (count($report['violation_samples']) < self::MAX_VIOLATION_SAMPLES) {
                $report['violation_samples'][] = [
                    'code' => $code,
                    'message' => $message,
                    'turn_id' => $turn?->turn_id,
                    'turn_db_id' => $turn?->id,
                    'run_id' => $run?->id,
                    'event_id' => $event?->id,
                ];
            } else {
                $report['samples_truncated'] = true;
            }
        };

        VoiceTurn::query()
            ->where('source', 'browser_voice_v2')
            ->select([
                'id', 'turn_id', 'conversation_session_id', 'user_message_id', 'final_assistant_message_id',
                'state', 'terminal_at', 'hard_deadline_at', 'no_progress_deadline_at', 'metadata',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $turns) use (&$report, $violation): void {
                $turnIds = $turns->pluck('id')->all();
                $stableTurnIds = $turns->pluck('turn_id')->all();
                $sessionIds = $turns->pluck('conversation_session_id')->unique()->values()->all();
                $messages = ConversationMessage::query()
                    ->whereIn('conversation_session_id', $sessionIds)
                    ->whereIn('client_turn_id', $stableTurnIds)
                    ->whereIn('role', ['user', 'assistant'])
                    ->get(['id', 'conversation_session_id', 'client_turn_id', 'role']);
                $messagesByTurn = $messages->groupBy(
                    fn (ConversationMessage $message): string => $this->messageKey(
                        (int) $message->conversation_session_id,
                        (string) $message->client_turn_id,
                    ),
                );
                $runsByTurn = AssistantRun::query()
                    ->whereIn('voice_turn_id', $turnIds)
                    ->get([
                        'id', 'voice_turn_id', 'lane', 'handler', 'label', 'status', 'assistant_message_id',
                        'metadata', 'result', 'error', 'completed_at', 'cancelled_at',
                    ])
                    ->groupBy('voice_turn_id');
                $eventsByTurn = VoiceTurnEvent::query()
                    ->whereIn('voice_turn_id', $turnIds)
                    ->get(['id', 'voice_turn_id', 'payload'])
                    ->groupBy('voice_turn_id');

                foreach ($turns as $turn) {
                    if (! $turn instanceof VoiceTurn) {
                        continue;
                    }
                    $report['counts']['turns']++;
                    $key = $this->messageKey((int) $turn->conversation_session_id, $turn->turn_id);
                    /** @var Collection<int, ConversationMessage> $turnMessages */
                    $turnMessages = $messagesByTurn->get($key, collect());
                    $report['counts']['messages'] += $turnMessages->count();
                    /** @var Collection<int, AssistantRun> $turnRuns */
                    $turnRuns = $runsByTurn->get($turn->id, collect());
                    /** @var Collection<int, VoiceTurnEvent> $turnEvents */
                    $turnEvents = $eventsByTurn->get($turn->id, collect());
                    $report['counts']['events'] += $turnEvents->count();

                    $this->auditTurnMessages($turn, $turnMessages, $violation);
                    $this->auditTurnLifecycle($turn, $turnRuns, $violation);
                    $this->auditRawAudioKeys($turn->metadata, 'turn.metadata', function (string $path) use ($violation, $turn): void {
                        $violation('raw_audio_key_in_turn_metadata', "Raw-audio key found at {$path}.", $turn);
                    });
                    foreach ($turnEvents as $event) {
                        $this->auditRawAudioKeys($event->payload, 'event.payload', function (string $path) use ($violation, $turn, $event): void {
                            $violation('raw_audio_key_in_event_metadata', "Raw-audio key found at {$path}.", $turn, null, $event);
                        });
                    }
                }
            }, 'id');

        AssistantRun::query()
            ->where('source', 'browser_voice_v2')
            ->select([
                'id', 'voice_turn_id', 'lane', 'handler', 'status', 'metadata', 'result', 'completed_at', 'cancelled_at',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $runs) use (&$report, $violation): void {
                $report['counts']['runs'] += $runs->count();
                $existingTurnIds = VoiceTurn::query()
                    ->whereIn('id', $runs->pluck('voice_turn_id')->filter()->unique()->values()->all())
                    ->pluck('id')
                    ->flip();

                foreach ($runs as $run) {
                    if (! $run instanceof AssistantRun) {
                        continue;
                    }
                    if ($run->voice_turn_id === null || ! $existingTurnIds->has($run->voice_turn_id)) {
                        $violation('orphan_voice_run', 'Browser Voice v2 run has no matching voice turn.', null, $run);
                    }
                    $this->auditRunLifecycle($run, $violation);
                    $this->auditRawAudioKeys($run->metadata, 'run.metadata', function (string $path) use ($violation, $run): void {
                        $violation('raw_audio_key_in_run_metadata', "Raw-audio key found at {$path}.", null, $run);
                    });
                    $this->auditRawAudioKeys($run->result, 'run.result', function (string $path) use ($violation, $run): void {
                        $violation('raw_audio_key_in_run_result', "Raw-audio key found at {$path}.", null, $run);
                    });
                }
            }, 'id');

        ksort($report['violations_by_code']);
        $report['status'] = $report['counts']['violations'] === 0 ? 'pass' : 'fail';

        return $report;
    }

    /**
     * @param  Collection<int, ConversationMessage>  $messages
     * @param  callable(string, string, ?VoiceTurn, ?AssistantRun, ?VoiceTurnEvent): void  $violation
     */
    private function auditTurnMessages(VoiceTurn $turn, Collection $messages, callable $violation): void
    {
        $userMessages = $messages->where('role', 'user')->values();
        $assistantMessages = $messages->where('role', 'assistant')->values();
        if ($userMessages->count() !== 1) {
            $violation('user_message_count', "Expected exactly one matching user message; found {$userMessages->count()}.", $turn);
        } elseif ((int) $turn->user_message_id !== (int) $userMessages->first()?->id) {
            $violation('user_message_pointer_mismatch', 'Turn user_message_id does not point to its sole matching user message.', $turn);
        }

        if (in_array($turn->state, [VoiceTurnState::Completed, VoiceTurnState::Failed], true)) {
            if ($assistantMessages->count() !== 1) {
                $violation('final_message_count', "Expected exactly one matching final assistant message; found {$assistantMessages->count()}.", $turn);
            } elseif ((int) $turn->final_assistant_message_id !== (int) $assistantMessages->first()?->id) {
                $violation('final_message_pointer_mismatch', 'Turn final_assistant_message_id does not point to its sole matching final.', $turn);
            }
        } elseif ($turn->state === VoiceTurnState::Canceled) {
            if ($assistantMessages->isNotEmpty() || $turn->final_assistant_message_id !== null) {
                $violation('canceled_turn_has_final', 'Canceled turn must not retain a normal-chat final assistant message.', $turn);
            }
        } elseif ($assistantMessages->isNotEmpty() || $turn->final_assistant_message_id !== null) {
            $violation('nonterminal_turn_has_final', 'Nonterminal turn has a final assistant message.', $turn);
        }
    }

    /**
     * @param  Collection<int, AssistantRun>  $runs
     * @param  callable(string, string, ?VoiceTurn, ?AssistantRun, ?VoiceTurnEvent): void  $violation
     */
    private function auditTurnLifecycle(VoiceTurn $turn, Collection $runs, callable $violation): void
    {
        $terminal = $turn->state->isTerminal();
        if ($terminal && $turn->terminal_at === null) {
            $violation('terminal_timestamp_missing', 'Terminal turn has no terminal_at timestamp.', $turn);
        }
        if (! $terminal && $turn->hard_deadline_at !== null && $turn->hard_deadline_at->isPast()) {
            $violation('active_hard_deadline_exceeded', 'Nonterminal turn is past its hard deadline.', $turn);
        }
        if (! $terminal && $turn->no_progress_deadline_at !== null && $turn->no_progress_deadline_at->isPast()) {
            $violation('active_no_progress_deadline_exceeded', 'Nonterminal turn is past its no-progress deadline.', $turn);
        }

        $activeRuns = $runs->whereIn('status', self::ACTIVE_RUN_STATUSES);
        /** @var Collection<int, AssistantRun> $requiredRuns */
        $requiredRuns = $runs->filter(
            fn (AssistantRun $run): bool => data_get($run->metadata, 'required', true) !== false,
        );
        if ($terminal && $activeRuns->isNotEmpty()) {
            $violation('terminal_turn_has_active_run', 'Terminal turn still has queued, running, or finalizing runs.', $turn, $activeRuns->first());
        }
        if (! $terminal && $requiredRuns->isNotEmpty() && $requiredRuns->every(
            fn (AssistantRun $run): bool => in_array($run->status, self::TERMINAL_RUN_STATUSES, true),
        )) {
            $violation('required_run_barrier_not_finalized', 'Every required run is terminal but the turn is not.', $turn);
        }
        if ($turn->state === VoiceTurnState::Completed) {
            $invalid = $requiredRuns->first(fn (AssistantRun $run): bool => $run->status !== 'completed');
            if ($invalid instanceof AssistantRun) {
                $violation('completed_turn_run_mismatch', 'Completed turn has a required run that is not completed.', $turn, $invalid);
            }
            $pointerMismatch = $requiredRuns->first(fn (AssistantRun $run): bool => $run->status === 'completed'
                && (int) $run->assistant_message_id !== (int) $turn->final_assistant_message_id);
            if ($pointerMismatch instanceof AssistantRun) {
                $violation('completed_run_final_pointer_mismatch', 'Completed run does not point to the turn final message.', $turn, $pointerMismatch);
            }
        } elseif ($turn->state === VoiceTurnState::Failed && $requiredRuns->isNotEmpty()) {
            if ($requiredRuns->contains(fn (AssistantRun $run): bool => ! in_array($run->status, self::TERMINAL_RUN_STATUSES, true))) {
                $violation('failed_turn_run_mismatch', 'Failed turn has a nonterminal required run.', $turn);
            }
            if (! $requiredRuns->contains('status', 'failed')) {
                $violation('failed_turn_without_failed_run', 'Failed turn with required runs has no failed run.', $turn);
            }
        } elseif ($turn->state === VoiceTurnState::Canceled && $requiredRuns->isNotEmpty()) {
            if ($requiredRuns->contains(fn (AssistantRun $run): bool => ! in_array($run->status, self::TERMINAL_RUN_STATUSES, true))) {
                $violation('canceled_turn_run_mismatch', 'Canceled turn has a nonterminal required run.', $turn);
            }
            if (! $requiredRuns->contains('status', 'cancelled')) {
                $violation('canceled_turn_without_canceled_run', 'Canceled turn with required runs has no canceled run.', $turn);
            }
        }
    }

    /** @param callable(string, string, ?VoiceTurn, ?AssistantRun, ?VoiceTurnEvent): void $violation */
    private function auditRunLifecycle(AssistantRun $run, callable $violation): void
    {
        $lane = trim((string) $run->lane);
        if ($lane === '' || VoiceTurnLane::tryFrom($lane) === null) {
            $violation('voice_run_lane_missing_or_invalid', 'Browser Voice v2 run has no explicit valid lane.', null, $run);
        }
        if (trim((string) $run->handler) === '') {
            $violation('voice_run_handler_missing', 'Browser Voice v2 run has no explicit handler.', null, $run);
        }
        if (! in_array($run->status, [...self::ACTIVE_RUN_STATUSES, ...self::TERMINAL_RUN_STATUSES], true)) {
            $violation('unknown_voice_run_status', "Browser Voice v2 run has unsupported status {$run->status}.", null, $run);

            return;
        }
        if (in_array($run->status, self::TERMINAL_RUN_STATUSES, true) && $run->completed_at === null) {
            $violation('run_terminal_timestamp_missing', 'Terminal run has no completed_at timestamp.', null, $run);
        }
        if ($run->status === 'cancelled' && $run->cancelled_at === null) {
            $violation('run_cancellation_timestamp_missing', 'Canceled run has no cancelled_at timestamp.', null, $run);
        }
        $resultStatus = trim((string) data_get($run->result, 'status'));
        if ($resultStatus !== '' && $resultStatus !== $run->status) {
            $violation('run_result_status_mismatch', "Run status {$run->status} disagrees with result status {$resultStatus}.", null, $run);
        }
    }

    /** @param callable(string): void $violation */
    private function auditRawAudioKeys(mixed $value, string $path, callable $violation): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            $nestedPath = $path.'.'.$key;
            $absenceMarker = (string) $key === 'raw_audio_retained' && $nested === false;
            if (! $absenceMarker && $this->privacy->isRawAudioKey((string) $key)) {
                $violation($nestedPath);
            }
            if (is_array($nested)) {
                $this->auditRawAudioKeys($nested, $nestedPath, $violation);
            }
        }
    }

    private function messageKey(int $sessionId, string $turnId): string
    {
        return $sessionId.'|'.$turnId;
    }
}
