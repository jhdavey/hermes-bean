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
    ) {}

    public function createSession(User $user, ?int $workspaceId = null): BeanSession
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $workspaceId);
        return BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Bean chat',
            'status' => 'active',
            'metadata' => ['privacy_mode' => true, 'wake_phrase' => 'Hey Bean'],
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

    public function handleMessage(User $user, string $content, ?int $sessionId = null, ?int $workspaceId = null): array
    {
        $session = $sessionId
            ? BeanSession::query()->where('user_id', $user->id)->findOrFail($sessionId)
            : $this->createSession($user, $workspaceId);

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

        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'text',
            'input' => $content,
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
            $proposal = $this->model->propose($session, $content);
            $actions = is_array($proposal['actions'] ?? null) ? $proposal['actions'] : [];
            $results = [];

            foreach ($actions as $action) {
                if (! is_array($action)) continue;
                $name = (string) ($action['action'] ?? '');
                if ($name === '') continue;
                $args = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
                $result = $this->executor->execute($session, $run, $name, $args);
                $results[] = ['action' => $name, 'arguments' => $args, ...$result];
            }

            $assistantText = $this->finalResponse($session, $content, (string) ($proposal['response'] ?? ''), $results);
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

            $run->update([
                'status' => collect($results)->contains(fn ($result): bool => ($result['requires_confirmation'] ?? false) === true) ? 'waiting_confirmation' : 'completed',
                'model' => $proposal['model'] ?? null,
                'output' => $assistantText,
                'metadata' => ['results' => $results],
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
            $run = $confirmation->run ?: BeanRun::create([
                'bean_session_id' => $session->id,
                'user_id' => $user->id,
                'workspace_id' => $session->workspace_id,
                'status' => 'running',
                'mode' => 'confirmation',
                'started_at' => now(),
            ]);
            $result = $this->executor->execute($session, $run, $confirmation->action, $confirmation->arguments ?? [], true);
            $confirmation->update(['status' => ($result['ok'] ?? false) ? 'approved' : 'failed', 'approved_at' => now()]);
            $text = ($result['ok'] ?? false) ? 'Done — I completed the confirmed action.' : 'I could not complete that confirmed action.';
            BeanMessage::create(['bean_session_id' => $session->id, 'bean_run_id' => $run->id, 'user_id' => $user->id, 'role' => 'assistant', 'content' => $text, 'metadata' => ['result' => $result]]);
            $run->update(['status' => ($result['ok'] ?? false) ? 'completed' : 'failed', 'output' => $text, 'metadata' => ['result' => $result], 'completed_at' => now()]);
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
        $weatherResponse = $this->weatherResponse($results);
        if ($weatherResponse !== null) return $weatherResponse;
        $recipeResponse = $this->recipeResponse($results);
        if ($recipeResponse !== null) return $recipeResponse;
        $noteMutationResponse = $this->noteMutationResponse($results);
        if ($noteMutationResponse !== null) return $noteMutationResponse;
        $listResponse = $this->listResponse($results);
        if ($listResponse !== null) return $listResponse;
        if ($this->shouldSynthesize($results)) {
            $synthesized = $this->model->synthesizeAnswer($session, $userMessage, $proposed, $results);
            if ($synthesized !== null) return $synthesized;
        }
        $resourceQueryResponse = $this->resourceQueryResponse($results);
        if ($resourceQueryResponse !== null) return $resourceQueryResponse;
        $contextResponse = $this->contextResponse($results);
        if ($contextResponse !== null) return $contextResponse;
        $completed = collect($results)->filter(fn ($result): bool => ($result['ok'] ?? false) === true)->count();
        if ($completed > 0) return $proposed !== '' ? $proposed.' Done.' : 'Done.';
        return $proposed !== '' ? $proposed : 'I’m here. Ask me to help with your calendar, tasks, reminders, notes, date/time, or weather.';
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

    private function weatherResponse(array $results): ?string
    {
        $weather = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && ($result['action'] ?? null) === 'weather.lookup');
        if (! $weather) return null;

        $location = trim((string) ($weather['location'] ?? '')) ?: 'that location';
        $current = is_array($weather['current'] ?? null) ? $weather['current'] : [];
        $currentUnits = is_array($weather['current_units'] ?? null) ? $weather['current_units'] : [];
        $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
        $dailyUnits = is_array($weather['daily_units'] ?? null) ? $weather['daily_units'] : [];

        $temperature = $this->weatherValue($current['temperature_2m'] ?? null, $currentUnits['temperature_2m'] ?? '°F');
        $feelsLike = $this->weatherValue($current['apparent_temperature'] ?? null, $currentUnits['apparent_temperature'] ?? '°F');
        $high = $this->weatherValue(data_get($forecast, 'temperature_2m_max.0'), $dailyUnits['temperature_2m_max'] ?? '°F');
        $low = $this->weatherValue(data_get($forecast, 'temperature_2m_min.0'), $dailyUnits['temperature_2m_min'] ?? '°F');
        $precipChance = $this->weatherValue(data_get($forecast, 'precipitation_probability_max.0'), $dailyUnits['precipitation_probability_max'] ?? '%');

        if ($temperature !== null && $feelsLike !== null && $high !== null && $low !== null && $precipChance !== null) {
            return "Right now in {$location}, it’s {$temperature} and feels like {$feelsLike}. Today’s forecast is {$high} high / {$low} low with a {$precipChance} precipitation chance.";
        }

        if ($high !== null && $low !== null) {
            $precip = $precipChance !== null ? " with a {$precipChance} precipitation chance" : '';
            return "Today’s forecast for {$location} is {$high} high / {$low} low{$precip}.";
        }

        if ($temperature !== null) {
            $feels = $feelsLike !== null ? " and feels like {$feelsLike}" : '';
            return "Right now in {$location}, it’s {$temperature}{$feels}.";
        }

        return null;
    }

    private function weatherValue(mixed $value, mixed $unit): ?string
    {
        if ($value === null || $value === '') return null;
        $number = is_numeric($value) ? (float) $value : null;
        $formatted = $number !== null && floor($number) === $number ? (string) (int) $number : (string) $value;
        return $formatted.(string) $unit;
    }

    private function recipeResponse(array $results): ?string
    {
        $recipe = collect($results)->first(fn ($result): bool => ($result['ok'] ?? false) === true
            && ($result['action'] ?? null) === 'recipe.lookup');
        if (! $recipe) return null;
        $title = trim((string) ($recipe['title'] ?? $recipe['query'] ?? 'recipe'));
        $summary = trim((string) ($recipe['summary'] ?? ''));
        if ($summary !== '') return "I found a simple {$title} recipe: {$summary}";
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
        if (($arguments['generated_recipe'] ?? false) === true) {
            $subject = preg_replace('/\s+recipe$/i', '', $title) ?: $title;
            return 'I created a recipe note for '.mb_strtolower($subject).' with ingredients and quick steps.';
        }
        if (($arguments['generated_meal_plan'] ?? false) === true) {
            return 'I created a note with five simple dinner meals for this coming week.';
        }
        if (($arguments['generated_recipe_followup'] ?? false) === true) {
            return "I added simple recipes under each of the five meals in {$title}.";
        }
        return null;
    }

    private function shouldSynthesize(array $results): bool
    {
        if (collect($results)->contains(fn ($result): bool => (bool) data_get($result, 'arguments.skip_synthesis'))) return false;

        return collect($results)->contains(fn ($result): bool => ($result['ok'] ?? false) === true
            && in_array($result['action'] ?? '', ['resource.query', 'resource.relationships', 'task.list', 'task.search', 'task.context', 'reminder.list', 'calendar_event.list', 'note.list'], true));
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
        $count = $items->count();
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
        $more = $count > 5 ? ' and '.($count - 5).' more' : '';
        return 'You have '.$count.' '.$label.': '.$listText.$more.'.';
    }

    private function todayListBreakdown(array $list, \Illuminate\Support\Collection $items): ?string
    {
        $action = (string) ($list['action'] ?? '');
        $dateField = $action === 'reminder.list' ? 'remind_at' : 'due_at';
        $start = now()->startOfDay();
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
