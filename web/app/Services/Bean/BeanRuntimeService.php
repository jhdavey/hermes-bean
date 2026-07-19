<?php

namespace App\Services\Bean;

use App\Models\BeanConfirmationRequest;
use App\Models\BeanMessage;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Throwable;

class BeanRuntimeService
{
    public function __construct(
        private readonly BeanTextModel $model,
        private readonly BeanActionExecutor $executor,
        private readonly BeanActivityLogger $activity,
        private readonly BeanTimeContext $timeContext,
    ) {}

    public function createSession(User $user, ?int $workspaceId = null, ?string $clientTimezone = null): BeanSession
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $workspaceId);
        $timezone = $this->timeContext->normalizeTimezone($clientTimezone);
        return BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Bean chat',
            'status' => 'active',
            'metadata' => array_filter([
                'privacy_mode' => true,
                'wake_phrase' => 'Hey Bean',
                'client_timezone' => $timezone,
                'timezone_source' => $timezone !== null ? 'browser' : 'app_default',
            ]),
        ]);
    }

    private function isAffirmativeConfirmationReply(string $content): bool
    {
        $lower = mb_strtolower(trim(preg_replace('/[^\pL\pN\s]/u', ' ', $content) ?: $content));
        $lower = trim(preg_replace('/\s+/', ' ', $lower) ?: $lower);

        return preg_match('/^(yes|yeah|yep|yup|ok|okay|confirm|confirmed|approve|approved|do it|go ahead|that is right|that\s right)$/u', $lower) === 1;
    }

    private function withConversationState(BeanSession $session, array $payload): array
    {
        return [
            ...$payload,
            'session' => $session->refresh(),
            'messages' => $session->messages()->latest('id')->limit(20)->get()->reverse()->values(),
            'activity' => $session->activityEvents()->latest('id')->limit(50)->get()->reverse()->values(),
            'confirmations' => BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('status', 'pending')
                ->latest('id')
                ->get(),
        ];
    }

    public function handleMessage(User $user, string $content, ?int $sessionId = null, ?int $workspaceId = null, ?string $clientTimezone = null): array
    {
        $session = $sessionId
            ? BeanSession::query()->where('user_id', $user->id)->findOrFail($sessionId)
            : $this->createSession($user, $workspaceId, $clientTimezone);
        if ($clientTimezone !== null && $sessionId) {
            $timezone = $this->timeContext->normalizeTimezone($clientTimezone);
            if ($timezone !== null) {
                $metadata = is_array($session->metadata) ? $session->metadata : [];
                $session->forceFill(['metadata' => [...$metadata, 'client_timezone' => $timezone, 'timezone_source' => 'browser']])->save();
                $session = $session->refresh();
            }
        }

        if ($this->isAffirmativeConfirmationReply($content) && $sessionId) {
            $confirmation = BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();
            if ($confirmation) {
                return $this->withConversationState($session, $this->approveConfirmation($user, $confirmation->id));
            }
        }

        $timeContext = $this->timeContext->forSession($session);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'text',
            'input' => $content,
            'metadata' => array_filter([
                'client_timezone' => $timeContext['timezone'],
                'time_context' => $timeContext,
            ]),
            'started_at' => now(),
        ]);

        BeanMessage::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $content,
        ]);
        $this->activity->log($session, $run, 'user_message', $content);
        $this->activity->log($session, $run, 'status', 'Thinking...', ['mode' => 'thinking']);

        try {
            $results = [];
            $assistantText = '';
            $modelName = null;
            $maxTurns = 8;

            for ($turn = 0; $turn < $maxTurns; $turn++) {
                $step = $this->model->nextStep($session, $content, $results);
                if ($modelName === null || (is_string($step['model'] ?? null) && $step['model'] !== 'local-heuristic')) {
                    $modelName = $step['model'] ?? $modelName;
                }
                $final = trim((string) ($step['final_response'] ?? ''));
                $name = trim((string) ($step['action'] ?? ''));
                $args = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];

                if ($name === '') {
                    $assistantText = $final;
                    break;
                }
                $alreadyRan = collect($results)->contains(function (array $result) use ($name, $args): bool {
                    return ($result['action'] ?? null) === $name
                        && json_encode(is_array($result['arguments'] ?? null) ? $result['arguments'] : []) === json_encode($args);
                });
                if ($alreadyRan) {
                    break;
                }

                $result = $this->executor->execute($session, $run, $name, $args);
                $results[] = ['action' => $name, 'arguments' => $args, ...$result];
                if (($result['requires_confirmation'] ?? false) === true) {
                    break;
                }
            }

            if ($assistantText === '') {
                $assistantText = $this->finalResponse($session, $content, '', $results);
            }
            $this->rememberResults($session, $results);
            BeanMessage::create([
                'bean_session_id' => $session->id,
                'bean_run_id' => $run->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $assistantText,
                'metadata' => ['results' => $results],
            ]);
            $this->activity->log($session, $run, 'assistant_message', $assistantText, ['results' => $results]);
            $this->activity->log($session, $run, 'status', 'Done', ['mode' => 'wake_listening']);

            $runMetadata = is_array($run->metadata) ? $run->metadata : [];
            $run->update([
                'status' => collect($results)->contains(fn ($result): bool => ($result['requires_confirmation'] ?? false) === true) ? 'waiting_confirmation' : 'completed',
                'model' => $modelName,
                'output' => $assistantText,
                'metadata' => [...$runMetadata, 'results' => $results],
                'completed_at' => now(),
            ]);
            app(\App\Services\Bean\Quality\BeanQualityAuditService::class)->traceRun($run->refresh());
        } catch (Throwable $exception) {
            $assistantText = 'I ran into a problem trying to do that.';
            $run->update(['status' => 'failed', 'error' => $exception->getMessage(), 'output' => $assistantText, 'completed_at' => now()]);
            BeanMessage::create(['bean_session_id' => $session->id, 'bean_run_id' => $run->id, 'user_id' => $user->id, 'role' => 'assistant', 'content' => $assistantText]);
            $this->activity->log($session, $run, 'error', $assistantText, ['error' => $exception->getMessage()]);
            app(\App\Services\Bean\Quality\BeanQualityAuditService::class)->traceRun($run->refresh());
        }

        $session->touch();

        return [
            'session' => $session->refresh(),
            'run' => $run->refresh(),
            'messages' => $session->messages()->latest('id')->limit(20)->get()->reverse()->values(),
            'activity' => $session->activityEvents()->latest('id')->limit(50)->get()->reverse()->values(),
            'confirmations' => \App\Models\BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('status', 'pending')
                ->latest('id')
                ->get(),
        ];
    }

    public function approveConfirmation(User $user, int $confirmationId): array
    {
        return DB::transaction(function () use ($user, $confirmationId): array {
            $confirmation = BeanConfirmationRequest::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($confirmationId);
            $session = $confirmation->session;
            $sessionTimeContext = $this->timeContext->forSession($session);
            $run = $confirmation->run ?: BeanRun::create([
                'bean_session_id' => $session->id,
                'user_id' => $user->id,
                'workspace_id' => $session->workspace_id,
                'status' => 'running',
                'mode' => 'confirmation',
                'metadata' => [
                    'client_timezone' => $sessionTimeContext['timezone'],
                    'time_context' => $sessionTimeContext,
                ],
                'started_at' => now(),
            ]);
            $result = $this->executor->execute($session, $run, $confirmation->action, $confirmation->arguments ?? [], true);
            $confirmation->update(['status' => ($result['ok'] ?? false) ? 'approved' : 'failed', 'approved_at' => now()]);
            $text = ($result['ok'] ?? false) ? 'Done — I completed the confirmed action.' : 'I could not complete that confirmed action.';
            BeanMessage::create(['bean_session_id' => $session->id, 'bean_run_id' => $run->id, 'user_id' => $user->id, 'role' => 'assistant', 'content' => $text, 'metadata' => ['result' => $result]]);
            $runMetadata = is_array($run->metadata) ? $run->metadata : [];
            $run->update(['status' => ($result['ok'] ?? false) ? 'completed' : 'failed', 'output' => $text, 'metadata' => [...$runMetadata, 'result' => $result], 'completed_at' => now()]);
            $this->activity->log($session, $run, 'assistant_message', $text, ['result' => $result]);
            return ['confirmation' => $confirmation->refresh(), 'run' => $run->refresh(), 'result' => $result];
        });
    }

    private function finalResponse(BeanSession $session, string $userMessage, string $proposed, array $results): string
    {
        $needsConfirmation = collect($results)->first(fn ($result): bool => ($result['requires_confirmation'] ?? false) === true);
        if ($needsConfirmation) return (string) ($needsConfirmation['summary'] ?? 'Please confirm before I do that.');
        $failed = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === false);
        if ($failed) {
            $ambiguousResponse = $this->ambiguousResponse($failed);
            if ($ambiguousResponse !== null) return $ambiguousResponse;

            return (string) ($failed['error'] ?? 'I could not complete that.');
        }
        $timeResponse = $this->timeResponse($results, $userMessage);
        if ($timeResponse !== null) return $timeResponse;
        $noteMutationResponse = $this->noteMutationResponse($results);
        if ($noteMutationResponse !== null) return $noteMutationResponse;
        $externalLookupResponse = $this->externalLookupResponse($results);
        if ($externalLookupResponse !== null) return $externalLookupResponse;
        $listResponse = $this->listResponse($results);
        if ($listResponse !== null) return $listResponse;
        $resourceQueryResponse = $this->resourceQueryResponse($results);
        if ($resourceQueryResponse !== null) return $resourceQueryResponse;
        $contextResponse = $this->contextResponse($results);
        if ($contextResponse !== null) return $contextResponse;
        $completed = collect($results)->filter(fn ($result): bool => ($result['ok'] ?? false) === true)->count();
        if ($completed > 0) return $proposed !== '' ? $proposed.' Done.' : 'Done.';
        return $proposed !== '' ? $proposed : 'I’m here. Ask me to help with your calendar, tasks, reminders, notes, date/time, or public lookups.';
    }

    private function timeResponse(array $results, string $userMessage): ?string
    {
        $time = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && ($result['action'] ?? null) === 'time.now'
            && isset($result['now']));
        if (! $time) return null;

        $timezone = (string) ($time['timezone'] ?? config('app.timezone', 'UTC'));
        $now = \Illuminate\Support\Carbon::parse((string) $time['now'])->timezone($timezone);
        $lower = mb_strtolower($userMessage);
        $asksDate = preg_match('/\b(date|today[’\']?s date|what day)\b/u', $lower) === 1;
        $asksTime = preg_match('/\b(time|current time|now)\b/u', $lower) === 1;
        if (! $asksDate && ! $asksTime) return null;

        if ($asksDate && ! $asksTime) {
            return "Today's date is ".$now->format('F j, Y').'.';
        }
        if ($asksDate && $asksTime) {
            return "Today's date is ".$now->format('F j, Y').', and the current time is '.$now->format('g:i A T').'.';
        }

        return 'The current time is '.$now->format('g:i A T').'.';
    }

    private function externalLookupResponse(array $results): ?string
    {
        $lookup = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && ($result['action'] ?? null) === 'external.lookup');
        if (! $lookup) return null;
        $summary = trim((string) ($lookup['summary'] ?? ''));
        $sources = collect($lookup['sources'] ?? [])->filter(fn ($source): bool => is_array($source))->values();
        $source = trim((string) ($lookup['source_url'] ?? ($sources->first()['url'] ?? '')));
        $sourceCount = $sources->count();
        $prefix = $sourceCount > 1 ? "I found {$sourceCount} sources" : 'I found this online';
        if ($summary !== '' && $source !== '') return "{$prefix}: {$summary} Source: {$source}";
        if ($summary !== '') return "{$prefix}: {$summary}";
        if ($sources->isNotEmpty()) {
            $first = $sources->first();
            $snippet = trim((string) ($first['snippet'] ?? $first['title'] ?? ''));
            $url = trim((string) ($first['url'] ?? ''));
            if ($snippet !== '' && $url !== '') return "{$prefix}: {$snippet} Source: {$url}";
            if ($snippet !== '') return "{$prefix}: {$snippet}";
        }
        return null;
    }

    private function noteMutationResponse(array $results): ?string
    {
        $note = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && in_array(($result['action'] ?? null), ['note.create', 'note.update'], true)
            && is_array($result['item'] ?? null));
        if (! $note) return null;
        $arguments = is_array($note['arguments'] ?? null) ? $note['arguments'] : [];
        $item = $note['item'];
        $title = trim((string) ($item['title'] ?? $arguments['title'] ?? 'that note'));
        if (($note['grounded_from'] ?? null) === 'external.lookup' || ($arguments['grounded_from'] ?? null) === 'external.lookup') {
            return "I created a source-grounded note: {$title}.";
        }
        return null;
    }

    private function resourceQueryResponse(array $results): ?string
    {
        $query = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && in_array(($result['action'] ?? null), ['resource.query', 'resource.relationships'], true)
            && is_array($result['items'] ?? null));
        if (! $query) return null;

        $items = collect($query['items'] ?? [])->filter(fn ($item): bool => is_array($item))->values();
        $explanations = collect($query['explanations'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && trim((string) ($item['reason'] ?? '')) !== '')
            ->values();
        if ($explanations->isNotEmpty()) {
            return $explanations->take(3)->map(fn (array $item): string => (string) $item['reason'])->implode(' ');
        }
        $workspaceQuestion = str_contains(strtolower((string) ($query['question'] ?? '')), 'workspace')
            || str_contains(strtolower((string) ($query['question'] ?? '')), 'where')
            || str_contains(strtolower((string) ($query['question'] ?? '')), 'live')
            || str_contains(strtolower((string) ($query['question'] ?? '')), 'belong')
            || (($query['include_workspaces'] ?? false) === true && collect($items)->flatMap(fn (array $item): array => $item['workspace_names'] ?? [])->isNotEmpty());
        if ($workspaceQuestion && $items->isNotEmpty()) {
            $titles = $items->map(fn (array $item): string => trim((string) ($item['title'] ?? '')))->filter()->unique(fn (string $title): string => mb_strtolower($title))->values();
            if ($titles->count() === 1) {
                $workspaceNames = $items
                    ->flatMap(fn (array $item): array => is_array($item['workspace_names'] ?? null) ? $item['workspace_names'] : [])
                    ->map(fn ($name): string => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                if ($workspaceNames !== []) {
                    $title = $titles->first();
                    $answer = count($workspaceNames) === 1
                        ? "{$title} is in the {$workspaceNames[0]} workspace."
                        : "{$title} is in these workspaces: ".$this->naturalList($workspaceNames).'.';
                    $kind = (string) data_get($query, 'arguments.correction_kind', '');
                    $heard = trim((string) data_get($query, 'arguments.heard_text', ''));
                    if ($kind === 'correction') return "Got it — you meant {$title}. {$answer}";
                    if ($kind === 'misheard' && $heard !== '') return "I heard “{$heard},” but I think you may mean {$title}. {$answer}";
                    return $answer;
                }
            }
        }
        if ($items->isEmpty()) {
            $resource = str_replace('_', ' ', (string) ($query['resource'] ?? 'items'));
            $lookup = trim((string) ($query['query'] ?? ''));
            return $lookup !== '' ? "I couldn’t find any matching {$resource} for {$lookup}." : "I couldn’t find any matching {$resource}.";
        }
        if ($items->count() === 1) {
            $item = $items->first();
            $title = trim((string) ($item['title'] ?? 'That item')) ?: 'That item';
            $workspaceNames = collect($item['workspace_names'] ?? [])->map(fn ($name): string => trim((string) $name))->filter()->values()->all();
            $question = strtolower((string) ($query['question'] ?? ''));
            if (str_contains($question, 'workspace') && $workspaceNames !== []) {
                return count($workspaceNames) === 1
                    ? "{$title} is in the {$workspaceNames[0]} workspace."
                    : "{$title} is in these workspaces: ".$this->naturalList($workspaceNames).'.';
            }
        }

        $resource = str_replace('_', ' ', (string) ($query['resource'] ?? 'items'));
        $titles = $items->take(5)->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')->all();
        $more = $items->count() > 5 ? ' and '.($items->count() - 5).' more' : '';
        return 'I found '.$items->count().' matching '.$resource.': '.$this->naturalList($titles).$more.'.';
    }

    private function rememberResults(BeanSession $session, array $results): void
    {
        $entities = collect($results)
            ->flatMap(function (array $result): array {
                if (is_array($result['item'] ?? null)) return [$result['item']];
                if (is_array($result['items'] ?? null)) return $result['items'];
                return [];
            })
            ->filter(fn ($item): bool => is_array($item) && isset($item['id'], $item['resource_type']))
            ->map(fn (array $item): array => [
                'id' => $item['id'],
                'type' => $item['resource_type'],
                'title' => $item['title'] ?? null,
                'workspace_names' => $item['workspace_names'] ?? [],
            ])
            ->values()
            ->all();

        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $queryContext = $this->recentQueryContextFromResults($results);
        if ($queryContext !== null) {
            $metadata['recent_query_context'] = $queryContext;
        }

        if ($entities !== []) {
            $existing = is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [];
            $metadata['recent_entities'] = collect($entities)
                ->merge($existing)
                ->unique(fn (array $entity): string => ($entity['type'] ?? '').':'.($entity['id'] ?? ''))
                ->take(20)
                ->values()
                ->all();
        }
        if ($queryContext !== null || $entities !== []) {
            $session->forceFill(['metadata' => $metadata])->save();
        }
    }

    private function recentQueryContextFromResults(array $results): ?array
    {
        foreach (array_reverse($results) as $result) {
            if (! is_array($result) || ($result['ok'] ?? false) !== true) continue;
            $resource = $this->resourceFromResult($result);
            if ($resource === null) continue;
            return [
                'resource' => $resource,
                'action' => $result['action'] ?? null,
                'time_label' => $result['time_label'] ?? data_get($result, 'arguments.time_label'),
            ];
        }

        return null;
    }

    private function resourceFromResult(array $result): ?string
    {
        $resource = (string) ($result['resource'] ?? data_get($result, 'arguments.resource') ?? '');
        if ($resource !== '') return $resource;

        return match ((string) ($result['action'] ?? '')) {
            'task.list', 'task.search', 'task.context' => 'tasks',
            'reminder.list', 'reminder.search' => 'reminders',
            'calendar_event.list', 'calendar_event.search' => 'calendar_events',
            'note.list', 'note.search' => 'notes',
            default => null,
        };
    }

    private function contextResponse(array $results): ?string
    {
        $context = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && ($result['context_type'] ?? null) === 'workspace'
            && is_array($result['item'] ?? null));
        if (! $context) return null;

        $item = $context['item'];
        $title = trim((string) ($item['title'] ?? 'That item')) ?: 'That item';
        $workspaceNames = collect($item['workspace_names'] ?? [])
            ->map(fn ($name): string => trim((string) $name))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        if ($workspaceNames === []) {
            return "I found {$title}, but I couldn’t determine its workspace.";
        }
        if (count($workspaceNames) === 1) {
            return "{$title} is in the {$workspaceNames[0]} workspace.";
        }

        return "{$title} is in these workspaces: ".$this->naturalList($workspaceNames).'.';
    }

    private function listResponse(array $results): ?string
    {
        $lists = collect($results)->filter(fn ($result): bool => ($result['ok'] ?? false) === true
            && is_array($result['items'] ?? null)
            && (str_ends_with((string) ($result['action'] ?? ''), '.list') || str_ends_with((string) ($result['action'] ?? ''), '.search')))
            ->values();
        if ($lists->isEmpty()) return null;
        if ($lists->count() === 1) return $this->singleListResponse($lists->first());

        $sentences = $lists
            ->map(fn (array $list): string => $this->singleListResponse($list))
            ->filter(fn (string $sentence): bool => $sentence !== '')
            ->values();

        return $sentences->isEmpty() ? null : $sentences->implode(' ');
    }

    private function singleListResponse(array $list): string
    {
        $label = $this->listLabel($list);
        $items = collect($list['items'] ?? [])->filter(fn ($item): bool => is_array($item));
        $count = max((int) ($list['total_count'] ?? 0), $items->count());
        if ($count === 0) {
            return "You don’t have any {$label}.";
        }

        $label = $this->listLabel($list, $count !== 1);
        $timeLabel = strtolower(trim((string) ($list['time_label'] ?? data_get($list, 'arguments.time_label') ?? '')));
        if ($timeLabel === 'today' && in_array((string) ($list['action'] ?? ''), ['task.list', 'reminder.list'], true)) {
            $grouped = $this->todayListBreakdown($list, $items);
            if ($grouped !== null) return $grouped;
        }
        $titles = $items
            ->take(5)
            ->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')
            ->values();
        $listText = $this->naturalList($titles->all());
        $more = $count > $titles->count() ? ' and '.($count - $titles->count()).' more' : '';
        return 'You have '.$count.' '.$label.': '.$listText.$more.'.';
    }

    private function todayListBreakdown(array $list, \Illuminate\Support\Collection $items): ?string
    {
        $action = (string) ($list['action'] ?? '');
        $dateField = $action === 'reminder.list' ? 'remind_at' : 'due_at';
        $timeContext = is_array($list['time_context'] ?? null) ? $list['time_context'] : $this->timeContext->forClientTimezone(null, 'app_default');
        $start = \Illuminate\Support\Carbon::parse((string) data_get($timeContext, 'now_utc', now('UTC')->toIso8601String()), 'UTC')
            ->timezone((string) data_get($timeContext, 'timezone', config('app.timezone', 'UTC')))
            ->startOfDay()
            ->utc();
        $overdue = $items->filter(function (array $item) use ($dateField, $start): bool {
            if (! isset($item[$dateField])) return false;
            return \Illuminate\Support\Carbon::parse((string) $item[$dateField])->lt($start);
        })->values();
        $today = $items->reject(function (array $item) use ($dateField, $start): bool {
            if (! isset($item[$dateField])) return false;
            return \Illuminate\Support\Carbon::parse((string) $item[$dateField])->lt($start);
        })->values();

        if ($overdue->isEmpty()) return null;

        $total = $items->count();
        $label = $this->listLabel($list, $total !== 1);
        $segments = [];
        if ($overdue->isNotEmpty()) {
            $segments[] = $overdue->count().' overdue — '.$this->naturalList($overdue->take(5)->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')->all());
        }
        if ($today->isNotEmpty()) {
            $segments[] = $today->count().' due today — '.$this->naturalList($today->take(5)->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')->all());
        }
        $more = $total > 10 ? ' and '.($total - 10).' more' : '';

        return 'You have '.$total.' '.$label.': '.implode('; ', $segments).$more.'.';
    }

    private function listLabel(array $list, bool $plural = true): string
    {
        $action = (string) ($list['action'] ?? '');
        $timeLabel = strtolower(trim((string) ($list['time_label'] ?? data_get($list, 'arguments.time_label') ?? '')));
        $noun = match ($action) {
            'task.list', 'task.search' => 'task',
            'reminder.list', 'reminder.search' => 'reminder',
            'calendar_event.list', 'calendar_event.search' => 'calendar event',
            'note.list', 'note.search' => 'note',
            default => 'item',
        };
        $kindQualifier = match ($action) {
            'task.list', 'task.search' => 'open',
            'reminder.list', 'reminder.search' => 'scheduled',
            'calendar_event.list', 'calendar_event.search' => in_array($timeLabel, ['today', 'tomorrow'], true) ? '' : 'upcoming',
            default => '',
        };
        if ($plural && ! str_ends_with($noun, 's')) {
            $noun .= 's';
        }
        $resourceLabel = trim($kindQualifier.' '.$noun);

        return match ($timeLabel) {
            'today' => in_array($action, ['task.list', 'reminder.list'], true) ? $resourceLabel.' due by today' : $resourceLabel.' for today',
            'tomorrow' => $resourceLabel.' tomorrow',
            'overdue' => 'overdue '.$resourceLabel,
            default => $resourceLabel,
        };
    }

    private function ambiguousResponse(array $result): ?string
    {
        if (($result['ambiguous'] ?? false) !== true || ! is_array($result['items'] ?? null)) {
            return null;
        }

        $action = (string) ($result['action'] ?? '');
        $noun = match (true) {
            str_starts_with($action, 'task.') => 'task',
            str_starts_with($action, 'reminder.') => 'reminder',
            str_starts_with($action, 'calendar_event.') => 'calendar event',
            str_starts_with($action, 'note.') => 'note',
            default => 'item',
        };
        $titles = collect($result['items'])
            ->filter(fn ($item): bool => is_array($item))
            ->take(5)
            ->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')
            ->values()
            ->all();

        if ($titles === []) {
            return null;
        }

        return 'I found multiple matching '.$noun.'s: '.$this->naturalList($titles).'. Which one should I use?';
    }

    private function naturalList(array $items): string
    {
        $items = array_values(array_filter(array_map(fn ($item): string => trim((string) $item), $items), fn ($item): bool => $item !== ''));
        if (count($items) <= 1) return $items[0] ?? '';
        if (count($items) === 2) return $items[0].' and '.$items[1];
        $last = array_pop($items);
        return implode(', ', $items).', and '.$last;
    }
}
