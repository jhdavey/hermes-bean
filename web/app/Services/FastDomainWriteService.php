<?php

namespace App\Services;

use App\Enums\VoiceTurnLane;
use App\Exceptions\BrowserVoiceHandlerException;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceTurn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FastDomainWriteService
{
    public function __construct(
        private readonly BrowserVoiceTypedWriteParser $typedWrites,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function execute(VoiceTurn $turn, ?AssistantRun $run = null): ?string
    {
        return DB::transaction(function () use ($turn, $run): ?string {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $lockedRun = $run instanceof AssistantRun
                ? AssistantRun::query()
                    ->whereKey($run->id)
                    ->where('voice_turn_id', $locked->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;
            if ($locked->state->isTerminal()) {
                return $this->reconcile($locked, $lockedRun);
            }
            if ($reconciled = $this->reconcile($locked, $lockedRun)) {
                return $reconciled;
            }

            $operation = clone $locked;
            if ($lockedRun instanceof AssistantRun) {
                $operation->setAttribute('handler', $lockedRun->handler ?: $locked->handler);
                $operation->setAttribute('transcript', trim((string) ($lockedRun->input ?: $locked->transcript)));
            }

            $result = match (strrchr((string) $operation->handler, '.') ?: '') {
                '.delete' => $this->delete($operation, $lockedRun),
                '.complete' => $this->completeTask($operation, $lockedRun),
                '.reschedule' => $this->reschedule($operation, $lockedRun),
                '.create' => $this->create($operation),
                default => null,
            };
            if ($result === null) {
                return null;
            }

            [$finalText, $action, $resourceType, $resourceId] = $result;
            $receipt = [
                'status' => 'committed',
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'final_text' => $finalText,
                'committed_at' => now()->toIso8601String(),
                'run_id' => $lockedRun?->id,
            ];
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if ($lockedRun instanceof AssistantRun) {
                $runMetadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
                $lockedRun->update(['metadata' => [...$runMetadata, 'write_receipt' => $receipt]]);
                $receiptKey = $lockedRun->idempotency_key ?: (string) $lockedRun->id;
                $receipts = is_array(data_get($metadata, 'write_receipts'))
                    ? data_get($metadata, 'write_receipts')
                    : [];
                $metadata['write_receipts'] = [...$receipts, $receiptKey => $receipt];
            }
            if (! $lockedRun instanceof AssistantRun || $locked->lane === VoiceTurnLane::AppWrite) {
                $metadata['write_receipt'] = $receipt;
            }
            $locked->update(['metadata' => $metadata]);

            return $finalText;
        }, 3);
    }

    /**
     * Persist model-generated note content through the same run-scoped,
     * idempotent receipt boundary as every other Browser Voice v2 write. The
     * model supplies text only and never receives app tool authority.
     */
    public function createGeneratedNote(VoiceTurn $turn, AssistantRun $run, string $content): ?string
    {
        return DB::transaction(function () use ($turn, $run, $content): ?string {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (($reconciled = $this->reconcile($locked, $lockedRun)) !== null) {
                return $reconciled;
            }
            if ($locked->state->isTerminal()
                || $lockedRun->handler !== 'agent.generate_note'
                || ! in_array($lockedRun->status, ['running', 'finalizing'], true)) {
                return null;
            }

            $body = trim($content);
            if ($body === '') {
                return null;
            }
            $this->assertNoteCreationAllowed($locked);
            $title = $this->generatedNoteTitle(trim((string) ($lockedRun->input ?: $locked->transcript)));
            $note = Note::create([
                ...$this->ownership($locked),
                'title' => $title,
                'plain_text' => $body,
                'body_html' => '<p>'.nl2br(e($body), false).'</p>',
                'metadata' => $this->resourceMetadata($locked),
            ]);
            $finalText = 'Done—I created the note “'.$note->title.'”.';
            $receipt = [
                'status' => 'committed',
                'action' => 'create',
                'resource_type' => 'note',
                'resource_id' => $note->id,
                'final_text' => $finalText,
                'committed_at' => now()->toIso8601String(),
                'run_id' => $lockedRun->id,
            ];
            $runMetadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $lockedRun->update(['metadata' => [...$runMetadata, 'write_receipt' => $receipt]]);
            $turnMetadata = is_array($locked->metadata) ? $locked->metadata : [];
            $receiptKey = $lockedRun->idempotency_key ?: (string) $lockedRun->id;
            $receipts = is_array(data_get($turnMetadata, 'write_receipts'))
                ? data_get($turnMetadata, 'write_receipts')
                : [];
            $locked->update(['metadata' => [
                ...$turnMetadata,
                'write_receipts' => [...$receipts, $receiptKey => $receipt],
            ]]);

            return $finalText;
        }, 3);
    }

    public function reconcile(VoiceTurn $turn, ?AssistantRun $run = null): ?string
    {
        $receipt = $run instanceof AssistantRun
            ? data_get($run->metadata, 'write_receipt')
            : data_get($turn->metadata, 'write_receipt');
        if (! is_array($receipt) || data_get($receipt, 'status') !== 'committed') {
            return null;
        }

        $finalText = trim((string) data_get($receipt, 'final_text'));

        return $finalText !== '' ? $finalText : null;
    }

    /** @return array{string, string, string, int|null}|null */
    private function create(VoiceTurn $turn): ?array
    {
        return match ($turn->handler) {
            'app.reminder.create' => $this->createReminder($turn),
            'app.task.create' => $this->createTask($turn),
            'app.note.create' => $this->createNote($turn),
            'app.calendar.create' => $this->createCalendarEvent($turn),
            default => null,
        };
    }

    /** @return array{string, string, string, int}|null */
    private function createReminder(VoiceTurn $turn): ?array
    {
        $intent = $this->typedWrites->parseCreate(
            $turn->transcript,
            $turn->handler,
            $this->timezone($turn),
            $turn->accepted_at,
            is_string(data_get($turn->metadata, 'contextual_reference.title'))
                ? data_get($turn->metadata, 'contextual_reference.title')
                : null,
        );
        if ($intent === null || ! $intent->isActionable()) {
            return null;
        }
        $title = $intent->title;
        $at = $intent->scheduledAt;
        if ($title === null || $at === null) {
            return null;
        }
        $reminder = Reminder::create([
            ...$this->ownership($turn),
            'conversation_session_id' => $turn->conversation_session_id,
            'title' => $title,
            'remind_at' => $at->copy()->utc(),
            'status' => 'scheduled',
            'metadata' => $this->resourceMetadata($turn),
        ]);

        return ['Done—I created the reminder “'.$reminder->title.'” for '.$this->spokenDateTime($at).'.', 'create', 'reminder', $reminder->id];
    }

    /** @return array{string, string, string, int}|null */
    private function createTask(VoiceTurn $turn): ?array
    {
        $title = $this->title($turn->transcript, 'task');
        if ($title === null) {
            return null;
        }
        $due = $this->typedWrites->parseScheduledAt(
            $turn->transcript,
            $this->timezone($turn),
            $turn->accepted_at,
        );
        $task = Task::create([
            ...$this->ownership($turn),
            'conversation_session_id' => $turn->conversation_session_id,
            'title' => $title,
            'type' => 'todo',
            'status' => 'open',
            'due_at' => $due?->copy()->utc(),
            'metadata' => $this->resourceMetadata($turn),
        ]);

        return ['Done—I created the task “'.$task->title.'”'.($due ? ' for '.$this->spokenDateTime($due) : '').'.', 'create', 'task', $task->id];
    }

    /** @return array{string, string, string, int}|null */
    private function createNote(VoiceTurn $turn): ?array
    {
        $this->assertNoteCreationAllowed($turn);
        $body = $this->noteBody($turn->transcript);
        $title = $this->title($turn->transcript, 'note')
            ?? ($body !== '' ? mb_substr($body, 0, 80) : null);
        if ($title === null) {
            return null;
        }
        $note = Note::create([
            ...$this->ownership($turn),
            'title' => $title,
            'plain_text' => $body,
            'body_html' => $body === '' ? '' : '<p>'.e($body).'</p>',
            'metadata' => $this->resourceMetadata($turn),
        ]);

        return ['Done—I created the note “'.$note->title.'”.', 'create', 'note', $note->id];
    }

    public function assertNoteCreationAllowed(VoiceTurn $turn): void
    {
        $message = $this->planLimits->noteCreationUpgradeMessage(User::findOrFail($turn->user_id));
        if ($message === null) {
            return;
        }

        throw new BrowserVoiceHandlerException(
            'subscription_limit_reached',
            $message,
            $message,
        );
    }

    /** @return array{string, string, string, int}|null */
    private function createCalendarEvent(VoiceTurn $turn): ?array
    {
        $intent = $this->typedWrites->parseCreate(
            $turn->transcript,
            $turn->handler,
            $this->timezone($turn),
            $turn->accepted_at,
        );
        if ($intent === null || ! $intent->isActionable()) {
            return null;
        }
        $title = $intent->title;
        $startsAt = $intent->scheduledAt;
        if ($title === null || $startsAt === null) {
            return null;
        }
        $event = CalendarEvent::create([
            ...$this->ownership($turn),
            'conversation_session_id' => $turn->conversation_session_id,
            'title' => $title,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $startsAt->copy()->addHour()->utc(),
            'status' => 'scheduled',
            'metadata' => $this->resourceMetadata($turn),
        ]);

        return ['Done—I scheduled “'.$event->title.'” for '.$this->spokenDateTime($startsAt).'.', 'create', 'calendar_event', $event->id];
    }

    /** @return array{string, string, string, int|null}|null */
    private function delete(VoiceTurn $turn, ?AssistantRun $run = null): ?array
    {
        $target = $this->target($turn, $run);
        if (! $target instanceof Model) {
            return null;
        }
        $title = trim((string) $target->getAttribute('title')) ?: 'that item';
        $type = match (true) {
            $target instanceof Reminder => 'reminder',
            $target instanceof Task => 'task',
            $target instanceof Note => 'note',
            $target instanceof CalendarEvent => 'calendar event',
            default => 'item',
        };
        $id = (int) $target->getKey();
        $target->delete();

        return ['Done—I deleted the '.$type.' “'.$title.'”.', 'delete', str_replace(' ', '_', $type), $id];
    }

    /** @return array{string, string, string, int}|null */
    private function completeTask(VoiceTurn $turn, ?AssistantRun $run = null): ?array
    {
        if ($turn->handler !== 'app.task.complete') {
            return null;
        }
        $task = $this->target($turn, $run);
        if (! $task instanceof Task) {
            return null;
        }
        $task->update(['status' => 'completed', 'completed_at' => now()]);

        return ['Done—I marked “'.$task->title.'” complete.', 'complete', 'task', $task->id];
    }

    /** @return array{string, string, string, int}|null */
    private function reschedule(VoiceTurn $turn, ?AssistantRun $run = null): ?array
    {
        $target = $this->target($turn, $run);
        $at = $this->typedWrites->parseRescheduleAt(
            $turn->transcript,
            $this->timezone($turn),
            $turn->accepted_at,
        );
        if (! $target instanceof Model || $at === null) {
            return null;
        }
        if ($target instanceof Reminder) {
            $target->update(['remind_at' => $at->copy()->utc()]);
            $type = 'reminder';
        } elseif ($target instanceof Task) {
            $target->update(['due_at' => $at->copy()->utc()]);
            $type = 'task';
        } elseif ($target instanceof CalendarEvent) {
            $duration = max(60, $target->starts_at?->diffInSeconds($target->ends_at, true) ?? 3600);
            $target->update([
                'starts_at' => $at->copy()->utc(),
                'ends_at' => $at->copy()->addSeconds($duration)->utc(),
            ]);
            $type = 'calendar event';
        } else {
            return null;
        }

        return ['Done—I moved “'.$target->title.'” to '.$this->spokenDateTime($at).'.', 'reschedule', str_replace(' ', '_', $type), (int) $target->getKey()];
    }

    private function target(VoiceTurn $turn, ?AssistantRun $run = null): ?Model
    {
        $query = match (true) {
            str_starts_with($turn->handler, 'app.reminder.') => $this->ownedQuery(Reminder::query(), $turn)->whereNotIn('status', ['completed', 'cancelled']),
            str_starts_with($turn->handler, 'app.task.') => $this->ownedQuery(Task::query(), $turn)->whereNotIn('status', ['completed', 'complete', 'done']),
            str_starts_with($turn->handler, 'app.note.') => $this->ownedQuery(Note::query(), $turn),
            str_starts_with($turn->handler, 'app.calendar.') => $this->ownedQuery(CalendarEvent::query(), $turn)->where('status', '!=', 'cancelled'),
            default => null,
        };
        if (! $query instanceof Builder) {
            return null;
        }

        $contextualCreate = $this->contextualCreateTarget($query, $turn, $run);
        if ($contextualCreate['bound']) {
            return $contextualCreate['target'];
        }

        if ($this->isContextualMutationReference($turn)
            && data_get($turn->metadata, 'prior_context_authorized') !== true) {
            return null;
        }

        $candidates = $query->latest('id')->limit(50)->get();
        $title = $this->typedWrites->parseMutationTargetTitle($turn->transcript);
        if ($title !== null) {
            $candidates = $candidates->filter(fn (Model $model): bool => str_contains(
                mb_strtolower((string) $model->getAttribute('title')),
                mb_strtolower($title),
            ));
        }

        $at = $this->typedWrites->parseMutationTargetAt(
            $turn->transcript,
            $this->timezone($turn),
            $turn->accepted_at,
        );
        if ($at !== null) {
            $timezone = $this->timezone($turn);
            $candidates = $candidates->filter(function (Model $model) use ($at, $timezone, $turn): bool {
                $value = $model instanceof Reminder
                    ? $model->remind_at
                    : ($model instanceof Task ? $model->due_at : ($model instanceof CalendarEvent ? $model->starts_at : null));
                if ($value === null) {
                    return false;
                }
                $local = $value->copy()->timezone($timezone);

                return $local->hour === $at->hour && $local->minute === $at->minute
                    && (! preg_match('/\b(?:today|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|may|june|july|august|september|october|november|december)\b/iu', $turn->transcript)
                        || $local->isSameDay($at));
            });
        }

        if ($candidates->count() > 1 && preg_match('/\b(?:that|it|the one)\b/iu', $turn->transcript) === 1) {
            $prior = VoiceTurn::query()
                ->where('user_id', $turn->user_id)
                ->where('conversation_session_id', $turn->conversation_session_id)
                ->where('turn_id', (string) data_get($turn->metadata, 'prior_turn_id', ''))
                ->first()?->finalAssistantMessage()
                ->value('content');
            if (is_string($prior)) {
                $mentioned = $candidates->filter(fn (Model $model): bool => str_contains(
                    mb_strtolower($prior),
                    mb_strtolower((string) $model->getAttribute('title')),
                ));
                if ($mentioned->count() === 1) {
                    return $mentioned->first();
                }
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function isContextualMutationReference(VoiceTurn $turn): bool
    {
        return preg_match('/^app\.(?:calendar|reminder|task|note)\.(?:delete|reschedule|complete)$/', $turn->handler) === 1
            && preg_match('/\b(?:titled|called|named|labeled|labelled)\b/iu', $turn->transcript) !== 1
            && (! str_ends_with($turn->handler, '.delete') || ! $this->typedWrites->hasClockTime($turn->transcript))
            && preg_match(
                '/\b(?:delete|remove|move|change|reschedule|set|complete|mark)\s+(?:(?:that|this)(?:\s+(?:reminder|task|note|(?:calendar\s+)?event|meeting|appointment))?|it|the\s+one)\b/iu',
                $turn->transcript,
            ) === 1;
    }

    /** @return array{bound: bool, target: ?Model} */
    private function contextualCreateTarget(Builder $query, VoiceTurn $turn, ?AssistantRun $run): array
    {
        $resourceLockKey = trim((string) ($run?->resource_lock_key ?? ''));
        $domain = match (true) {
            str_starts_with($turn->handler, 'app.reminder.') => 'reminder',
            str_starts_with($turn->handler, 'app.task.') => 'task',
            str_starts_with($turn->handler, 'app.note.') => 'note',
            str_starts_with($turn->handler, 'app.calendar.') => 'calendar',
            default => null,
        };
        $sameTurnDependency = $run instanceof AssistantRun
            ? data_get($run->metadata, 'contextual_create_dependency')
            : null;
        if (is_array($sameTurnDependency)) {
            if ($domain === null
                || data_get($sameTurnDependency, 'scope') !== 'same_turn'
                || data_get($sameTurnDependency, 'domain') !== $domain
                || data_get($sameTurnDependency, 'resource_lock_key') !== $resourceLockKey
                || data_get($sameTurnDependency, 'intended_resource_type') !== ($domain === 'calendar' ? 'calendar_event' : $domain)) {
                return ['bound' => true, 'target' => null];
            }

            $predecessorRun = AssistantRun::query()
                ->where('voice_turn_id', $turn->id)
                ->where('idempotency_key', (string) data_get($sameTurnDependency, 'predecessor_idempotency_key', ''))
                ->where('handler', (string) data_get($sameTurnDependency, 'predecessor_handler', ''))
                ->where('handler', "app.{$domain}.create")
                ->where('resource_lock_key', $resourceLockKey)
                ->first();

            return [
                'bound' => true,
                'target' => $this->targetFromCommittedCreateReceipt(
                    $query,
                    $predecessorRun,
                    $domain,
                    $turn->turn_id,
                ),
            ];
        }

        $dependencies = is_array(data_get($turn->metadata, 'contextual_create_dependencies'))
            ? data_get($turn->metadata, 'contextual_create_dependencies')
            : [];
        $dependency = $resourceLockKey !== '' ? ($dependencies[$resourceLockKey] ?? null) : null;
        if (! is_array($dependency)) {
            return ['bound' => false, 'target' => null];
        }

        if ($domain === null) {
            return ['bound' => true, 'target' => null];
        }
        $predecessor = VoiceTurn::query()
            ->whereKey((int) data_get($dependency, 'voice_turn_id', 0))
            ->where('turn_id', (string) data_get($dependency, 'turn_id', ''))
            ->where('user_id', $turn->user_id)
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('handler', "app.{$domain}.create")
            ->first();
        if (! $predecessor instanceof VoiceTurn
            || data_get($dependency, 'domain') !== $domain
            || data_get($dependency, 'resource_lock_key') !== $resourceLockKey) {
            return ['bound' => true, 'target' => null];
        }

        $predecessorRun = AssistantRun::query()
            ->where('voice_turn_id', $predecessor->id)
            ->where('handler', "app.{$domain}.create")
            ->where('resource_lock_key', $resourceLockKey)
            ->latest('id')
            ->first();

        return [
            'bound' => true,
            'target' => $this->targetFromCommittedCreateReceipt(
                $query,
                $predecessorRun,
                $domain,
                $predecessor->turn_id,
            ),
        ];
    }

    private function targetFromCommittedCreateReceipt(
        Builder $query,
        ?AssistantRun $predecessorRun,
        string $domain,
        string $expectedTurnId,
    ): ?Model {
        if (! $predecessorRun instanceof AssistantRun || $predecessorRun->status !== 'completed') {
            return null;
        }

        $receipt = data_get($predecessorRun->metadata, 'write_receipt');
        $expectedResourceType = $domain === 'calendar' ? 'calendar_event' : $domain;
        if (! is_array($receipt)
            || data_get($receipt, 'status') !== 'committed'
            || data_get($receipt, 'action') !== 'create'
            || data_get($receipt, 'resource_type') !== $expectedResourceType) {
            return null;
        }

        $target = (clone $query)->whereKey((int) data_get($receipt, 'resource_id', 0))->first();

        return $target instanceof Model
            && data_get($target->getAttribute('metadata'), 'browser_voice_turn_id') === $expectedTurnId
                ? $target
                : null;
    }

    private function ownedQuery(Builder $query, VoiceTurn $turn): Builder
    {
        return $query->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
    }

    /** @return array<string, mixed> */
    private function ownership(VoiceTurn $turn): array
    {
        return [
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
        ];
    }

    /** @return array<string, mixed> */
    private function resourceMetadata(VoiceTurn $turn): array
    {
        return ['browser_voice_turn_id' => $turn->turn_id, 'source' => 'browser_voice_v2'];
    }

    private function title(string $text, string $nounPattern): ?string
    {
        $patterns = [
            '/\b(?:titled|called|named|labeled|labelled)\s+[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow)\b|[”"]?[.!]*$)/iu',
            '/\b(?:'.$nounPattern.')\b\s+(?:titled|called|named|labeled|labelled)\s+[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow|that says|saying|with)\b|[”"]?[.!]*$)/iu',
            '/\b(?:create|add|make|set|schedule|save)\s+(?:a\s+)?(?:'.$nounPattern.')\b\s+(?:to\s+)?[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow|that says|saying|with)\b|[”"]?[.!]*$)/iu',
            '/\bremind me to\s+[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow)\b|[”"]?[.!]*$)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }
            $title = trim((string) $match[1], " \t\n\r\0\x0B\"“”");
            if ($title !== '') {
                return mb_substr($title, 0, 180);
            }
        }

        return null;
    }

    private function noteBody(string $text): string
    {
        if (preg_match('/\b(?:that says|saying|with(?: the)? (?:text|content))\s+[“"]?(.+?)[”"]?[.!]*$/iu', $text, $match) !== 1) {
            return '';
        }

        return trim((string) $match[1], " \t\n\r\0\x0B\"“”");
    }

    private function generatedNoteTitle(string $request): string
    {
        if (preg_match('/\bnote\s+(?:titled|called|named|labeled|labelled)\s+[“"]?(.+?)(?=[”"]?\s+(?:and\s+)?(?:put|include|add|write|fill)\b|[”"]?[.!?]|$)/iu', $request, $match) === 1) {
            $title = trim((string) $match[1], " \t\n\r\0\x0B\"“”");
            if ($title !== '') {
                return mb_substr($title, 0, 180);
            }
        }
        if (preg_match('/\b((?:(?:one|two|three|four|five|six|seven|\d+)[ -]day\s+)?(?:meal|dinner|lunch|travel|workout|study)\s+plan)\b/iu', $request, $match) === 1) {
            return mb_convert_case(trim((string) $match[1]), MB_CASE_TITLE, 'UTF-8');
        }

        return 'Bean note';
    }

    private function timezone(VoiceTurn $turn): string
    {
        $timezone = trim((string) data_get($turn->metadata, 'timezone', 'UTC')) ?: 'UTC';
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'UTC';
        }

        return $timezone;
    }

    private function spokenDateTime(Carbon $value): string
    {
        $date = $value->isToday() ? 'today' : ($value->isTomorrow() ? 'tomorrow' : $value->format('F jS'));
        $time = mb_strtolower($value->format('g:i a'));
        $time = str_replace(':00 ', ' ', $time);
        // Callers finish the sentence. Keep the meridiem natural without
        // embedding a second terminal period ("5 p.m..") in confirmations.
        $time = str_replace([' am', ' pm'], [' a.m', ' p.m'], $time);

        return "{$date} at {$time}";
    }
}
