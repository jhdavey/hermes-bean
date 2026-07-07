<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Models\AiUsageLog;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentProfileService;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\WorkspaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RunBeanProductionSmokeSuite extends Command
{
    protected $signature = 'bean:production-smoke
        {--email=bean-prod-smoke-suite@example.com : Dedicated production smoke user email}
        {--count=100 : Number of prompts to run}
        {--timeout=45 : Seconds to wait for each queued run}
        {--scenario=single : Smoke scenario to run: single, followups, or kpi}
        {--cleanup : Delete created smoke resources after the run}
        {--no-reset : Keep existing data in the default smoke account before running}
        {--suite-id= : Optional suite id for traceability}';

    protected $description = 'Run complex Bean assistant requests against a dedicated real production account.';

    public function handle(
        WorkspaceService $workspaces,
        AgentProfileService $profiles,
        AssistantRunService $runs,
    ): int {
        $count = max(1, min(100, (int) $this->option('count')));
        $timeout = max(5, min(180, (int) $this->option('timeout')));
        $suiteId = (string) ($this->option('suite-id') ?: 'prod-smoke-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(5)));

        $user = User::firstOrCreate(
            ['email' => strtolower(trim((string) $this->option('email')))],
            [
                'name' => 'Bean Production Smoke',
                'password' => Hash::make(Str::random(40)),
                'subscription_tier' => 'pro',
            ],
        );
        $user->forceFill(['subscription_tier' => 'pro'])->save();

        $workspace = $workspaces->resolveWorkspace($user, null);

        $profiles->ensureForWorkspace($workspace, $user);

        if (! $this->option('no-reset') && $user->email === 'bean-prod-smoke-suite@example.com') {
            $this->resetSmokeUserData($user);
        }

        $scenario = (string) $this->option('scenario');
        if ($scenario === 'followups') {
            return $this->runFollowupSuite($user, $workspace, $runs, $count, $timeout, $suiteId);
        }
        $prompts = $scenario === 'kpi' ? $this->kpiPrompts() : $this->prompts();

        $this->info("Running {$count} Bean production smoke requests as {$user->email} in workspace {$workspace->id}.");
        $this->line("Suite: {$suiteId}");

        $results = [];
        $startedAt = microtime(true);

        foreach (array_slice($prompts, 0, $count) as $index => $prompt) {
            $case = $index + 1;
            $session = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => "Production smoke {$case}",
                'status' => 'active',
                'runtime_mode' => 'tools',
                'metadata' => [
                    'prod_smoke' => true,
                    'suite_id' => $suiteId,
                    'case' => $case,
                ],
                'last_activity_at' => now(),
            ]);

            $queueStartedAt = microtime(true);
            $queued = $runs->queueRun($session, $prompt, [
                'source' => 'production_smoke',
                'prod_smoke' => true,
                'suite_id' => $suiteId,
                'case' => $case,
                'client_context' => [
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => now('America/New_York')->toIso8601String(),
                    'current_utc_time' => now('UTC')->toIso8601String(),
                ],
            ], 'production_smoke');
            $queueLatencyMs = $this->elapsedMs($queueStartedAt);

            $run = $this->waitForRun($queued['run'], $timeout);
            $assistant = $run->assistantMessage?->content ?? '';
            $durationMs = $run->created_at && $run->updated_at
                ? (int) $run->created_at->diffInMilliseconds($run->updated_at, true)
                : null;
            $qualityFailures = array_values(array_unique(array_merge(
                $this->assistantQualityFailures($prompt, $assistant),
                $this->workItemQualityFailures($run),
            )));
            $benchmark = $this->benchmarkMetricsForRun($prompt, $run, $queueLatencyMs, $qualityFailures);
            $failed = $run->status !== 'completed'
                || $this->containsFailureCopy($assistant)
                || $qualityFailures !== []
                || ! $benchmark['meets_kpi'];

            $results[] = [
                'case' => $case,
                'run_id' => $run->id,
                'session_id' => $session->id,
                'status' => $run->status,
                'benchmark_class' => $benchmark['class'],
                'first_response_ms' => $benchmark['first_response_ms'],
                'duration_ms' => $durationMs,
                'completion_target_ms' => $benchmark['completion_target_ms'],
                'first_planned_work_ms' => $benchmark['first_planned_work_ms'],
                'dashboard_freshness_ms' => $benchmark['dashboard_freshness_ms'],
                'failed' => $failed,
                'quality_failures' => $qualityFailures,
                'kpi_failures' => $benchmark['failures'],
                'prompt' => $prompt,
                'assistant' => str($assistant)->squish()->limit(220)->toString(),
                'error' => $run->error,
            ];

            $this->line(sprintf(
                '[%03d/%03d] %s run=%d %sms %s',
                $case,
                $count,
                $failed ? '<fg=red>FAIL</>' : '<fg=green>PASS</>',
                $run->id,
                $durationMs ?? '?',
                str((($qualityFailures === [] && $benchmark['failures'] === []) ? '' : '['.implode(', ', array_merge($qualityFailures, $benchmark['failures'])).'] ').($assistant ?: $run->error ?: 'No response'))->squish()->limit(150)->toString(),
            ));
        }

        $failed = collect($results)->where('failed', true)->values();
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $kpis = $this->kpiSummary($results);

        $summary = [
            'suite_id' => $suiteId,
            'scenario' => $scenario,
            'count' => count($results),
            'passed' => count($results) - $failed->count(),
            'failed' => $failed->count(),
            'elapsed_ms' => $elapsedMs,
            'kpis' => $kpis,
            'results' => $results,
        ];

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->option('cleanup')) {
            $this->cleanup($user, $suiteId);
            $this->warn('Cleaned up resources for suite '.$suiteId.'.');
        }

        return $failed->isEmpty() && $this->kpiSummaryMeetsTargets($kpis) ? self::SUCCESS : self::FAILURE;
    }

    private function runFollowupSuite(User $user, Workspace $workspace, AssistantRunService $runs, int $count, int $timeout, string $suiteId): int
    {
        $scenarios = array_slice($this->followupScenarios(), 0, $count);
        $this->info('Running '.count($scenarios)." Bean follow-up smoke conversations as {$user->email} in workspace {$workspace->id}.");
        $this->line("Suite: {$suiteId}");

        $results = [];
        $startedAt = microtime(true);

        foreach ($scenarios as $index => $scenario) {
            $case = $index + 1;
            $session = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => "Production follow-up smoke {$case}",
                'status' => 'active',
                'runtime_mode' => 'tools',
                'metadata' => [
                    'prod_smoke' => true,
                    'suite_id' => $suiteId,
                    'case' => $case,
                    'scenario' => 'followups',
                    'scenario_name' => $scenario['name'],
                ],
                'last_activity_at' => now(),
            ]);

            $steps = [];
            foreach ($scenario['steps'] as $stepIndex => $prompt) {
                $queued = $runs->queueRun($session->refresh(), $prompt, [
                    'source' => 'production_smoke',
                    'prod_smoke' => true,
                    'suite_id' => $suiteId,
                    'case' => $case,
                    'scenario' => 'followups',
                    'scenario_name' => $scenario['name'],
                    'step' => $stepIndex + 1,
                    'client_context' => [
                        'timezone' => 'America/New_York',
                        'timezone_offset' => '-04:00',
                        'timezone_offset_minutes' => -240,
                        'current_local_time' => now('America/New_York')->toIso8601String(),
                        'current_utc_time' => now('UTC')->toIso8601String(),
                    ],
                ], 'production_smoke');

                $run = $this->waitForRun($queued['run'], $timeout);
                $assistant = $run->assistantMessage?->content ?? '';
                $durationMs = $run->created_at && $run->updated_at
                    ? (int) $run->created_at->diffInMilliseconds($run->updated_at, true)
                    : null;
                $qualityFailures = array_values(array_unique(array_merge(
                    $this->assistantQualityFailures($prompt, $assistant),
                    $this->workItemQualityFailures($run),
                )));
                $stepFailed = $run->status !== 'completed' || $this->containsFailureCopy($assistant) || $qualityFailures !== [];

                $steps[] = [
                    'step' => $stepIndex + 1,
                    'run_id' => $run->id,
                    'status' => $run->status,
                    'duration_ms' => $durationMs,
                    'failed' => $stepFailed,
                    'quality_failures' => $qualityFailures,
                    'prompt' => $prompt,
                    'assistant' => str($assistant)->squish()->limit(220)->toString(),
                    'error' => $run->error,
                ];
            }

            $stateFailures = $this->followupStateFailures($session->refresh(), $scenario);
            $failed = collect($steps)->contains('failed', true) || $stateFailures !== [];
            $results[] = [
                'case' => $case,
                'scenario' => $scenario['name'],
                'session_id' => $session->id,
                'failed' => $failed,
                'state_failures' => $stateFailures,
                'steps' => $steps,
            ];

            $this->line(sprintf(
                '[%03d/%03d] %s scenario=%s %s',
                $case,
                count($scenarios),
                $failed ? '<fg=red>FAIL</>' : '<fg=green>PASS</>',
                $scenario['name'],
                $stateFailures === [] ? str(collect($steps)->last()['assistant'] ?? 'No response')->squish()->limit(120)->toString() : '['.implode(', ', $stateFailures).']',
            ));
        }

        $failed = collect($results)->where('failed', true)->values();
        $summary = [
            'suite_id' => $suiteId,
            'scenario' => 'followups',
            'count' => count($results),
            'passed' => count($results) - $failed->count(),
            'failed' => $failed->count(),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'results' => $results,
        ];

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($this->option('cleanup')) {
            $this->cleanup($user, $suiteId);
            $this->warn('Cleaned up resources for suite '.$suiteId.'.');
        }

        return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function waitForRun(AssistantRun $run, int $timeoutSeconds): AssistantRun
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $fresh = AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($run->id);
            if ($this->runIsReadyForSmokeJudgment($fresh)) {
                return $fresh;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        $recovered = app(AssistantRunService::class)->recoverStaleRun(
            AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($run->id),
            app(HermesRuntimeService::class),
        );

        $graceDeadline = microtime(true) + 5;
        do {
            $fresh = AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($recovered->id);
            if ($this->runIsReadyForSmokeJudgment($fresh)) {
                return $fresh;
            }

            usleep(250_000);
        } while (microtime(true) < $graceDeadline);

        return AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($recovered->id);
    }

    private function runIsReadyForSmokeJudgment(AssistantRun $run): bool
    {
        if (in_array($run->status, ['failed', 'cancelled'], true)) {
            return true;
        }

        if ($run->status !== 'completed') {
            return false;
        }

        return $run->assistantMessage instanceof ConversationMessage
            && trim((string) $run->assistantMessage->content) !== '';
    }

    private function containsFailureCopy(string $content): bool
    {
        return str($content)->lower()->contains([
            'bean could not finish',
            'could not finish that request',
            'could not complete the requested change',
            'i could not complete',
            'no usable result',
            'not get that live lookup back quickly enough',
            'reached today\'s ai usage limit',
            'reached today’s ai usage limit',
            'reached today\'s external lookup usage limit',
            'reached today’s external lookup usage limit',
            'ai usage limit reached',
            'usage limit',
        ]);
    }

    /**
     * @return list<string>
     */
    private function assistantQualityFailures(string $prompt, string $assistant): array
    {
        $promptText = str($prompt)->lower()->squish()->toString();
        $answerText = str($assistant)->lower()->squish()->toString();
        $failures = [];

        if ($answerText === '') {
            return ['empty_response'];
        }

        if (str_word_count($answerText) < 4) {
            $failures[] = 'too_short';
        }

        if (preg_match('/^(done|ok|okay|sure|yes)[.!]?$/u', $answerText)) {
            $failures[] = 'generic_acknowledgement';
        }

        if ($this->promptLooksLikeWriteRequest($promptText) && ! $this->answerLooksLikeWriteCompletion($answerText)) {
            $failures[] = 'missing_write_confirmation';
        }

        if (
            str_contains($promptText, 'weather for tomorrow')
            && ! $this->answerAsksUsefulClarifyingQuestion($answerText)
            && ! preg_match('/\b(high|low|weather|rain|storm|clear|overcast|drizzl|showery|precipitation|°f|degrees)\b/u', $answerText)
        ) {
            $failures[] = 'missing_weather_details';
        }

        if ($this->promptLooksLikePlacesLookup($promptText) && ! preg_match('/\b(address| mi| miles|[0-9]{3,}\s+[a-z])/u', $answerText)) {
            $failures[] = 'missing_place_details';
        }

        $specificPlaceFailure = $this->specificPlaceLookupFailure($promptText, $answerText);
        if ($specificPlaceFailure !== null) {
            $failures[] = $specificPlaceFailure;
        }

        if (str_contains($promptText, 'remember that') && ! preg_match('/\b(saved|remembered|bean knowledge|knowledge)\b/u', $answerText)) {
            $failures[] = 'missing_memory_confirmation';
        }

        if ($this->promptLooksLikeDayContextRequest($promptText) && ! preg_match('/\b(today|coming up|scheduled|calendar|event|task|reminder|next|plan)\b/u', $answerText)) {
            $failures[] = 'missing_day_context';
        }

        if ($this->promptLooksLikeRequestHistoryRecall($promptText) && ! preg_match('/\b(req-\d{3}|you asked|request history|asked)\b/u', $answerText)) {
            $failures[] = 'missing_request_history';
        }

        $specificHistoryFailure = $this->specificRequestHistoryFailure($promptText, $answerText);
        if ($specificHistoryFailure !== null) {
            $failures[] = $specificHistoryFailure;
        }

        if ($this->promptLooksLikeMemoryRecall($promptText) && ! preg_match('/\b(saved|remembered|prefer|preference|concise|status|updates?|errands?)\b/u', $answerText)) {
            $failures[] = 'missing_memory_recall';
        }

        $specificMemoryFailure = $this->specificMemoryRecallFailure($promptText, $answerText);
        if ($specificMemoryFailure !== null) {
            $failures[] = $specificMemoryFailure;
        }

        return array_values(array_unique($failures));
    }

    private function promptLooksLikeWriteRequest(string $promptText): bool
    {
        if ($this->promptLooksLikeRequestHistoryRecall($promptText) || $this->promptLooksLikeCapabilityQuestion($promptText)) {
            return false;
        }

        return (bool) preg_match('/\b(add|create|make|schedule|book|set|move|update|remind me|save a note|pin it|remember that)\b/u', $promptText)
            && ! str_contains($promptText, 'find the weather')
            && ! str_contains($promptText, 'find the nearest')
            && ! str_contains($promptText, 'find the closest');
    }

    private function answerLooksLikeWriteCompletion(string $answerText): bool
    {
        return (bool) preg_match('/\b(done|added|created|set|saved|updated|moved|scheduled|reminder|calendar|task|note|bean knowledge)\b/u', $answerText);
    }

    private function promptLooksLikePlacesLookup(string $promptText): bool
    {
        return str_contains($promptText, 'nearest ')
            || str_contains($promptText, 'closest ')
            || str_contains($promptText, 'nearby ');
    }

    private function specificPlaceLookupFailure(string $promptText, string $answerText): ?string
    {
        if (str_contains($promptText, '32820') && $this->promptLooksLikePlacesLookup($promptText) && (str_contains($answerText, 'ohio') || str_contains($answerText, '123 main'))) {
            return 'wrong_place_32820';
        }

        if (str_contains($promptText, '32820') && str_contains($promptText, 'wawa')) {
            return str_contains($answerText, '16959') || str_contains($answerText, 'e colonial')
                ? null
                : 'wrong_wawa_32820';
        }

        if (str_contains($promptText, '32820') && str_contains($promptText, 'starbucks')) {
            return str_contains($answerText, '321') || str_contains($answerText, 'avalon')
                ? null
                : 'wrong_starbucks_32820';
        }

        if (str_contains($promptText, '32820') && str_contains($promptText, 'home depot')) {
            return str_contains($answerText, '350') || str_contains($answerText, 'alafaya')
                ? null
                : 'wrong_home_depot_32820';
        }

        return null;
    }

    private function promptLooksLikeDayContextRequest(string $promptText): bool
    {
        return str_contains($promptText, 'coming up today')
            || str_contains($promptText, 'on my calendar and reminders today')
            || str_contains($promptText, 'left today')
            || str_contains($promptText, 'later today')
            || str_contains($promptText, 'review today')
            || str_contains($promptText, 'scheduled today')
            || str_contains($promptText, 'after lunch')
            || str_contains($promptText, 'remember today')
            || str_contains($promptText, 'look at today')
            || str_contains($promptText, 'remains today');
    }

    private function promptLooksLikeRequestHistoryRecall(string $promptText): bool
    {
        return str_contains($promptText, 'what did i ask')
            || str_contains($promptText, 'what request did i make')
            || str_contains($promptText, 'which request did i make')
            || str_contains($promptText, 'what was my earlier request')
            || str_contains($promptText, 'what did i request');
    }

    private function specificRequestHistoryFailure(string $promptText, string $answerText): ?string
    {
        if (! $this->promptLooksLikeRequestHistoryRecall($promptText)) {
            return null;
        }

        if (str_contains($promptText, 'req-011')) {
            return str_contains($answerText, 'req-011') ? null : 'wrong_request_history_req_011';
        }

        if (str_contains($promptText, 'dr chen cardio')) {
            return str_contains($answerText, 'dr chen cardio') && str_contains($answerText, 'req-011')
                ? null
                : 'wrong_request_history_dr_chen';
        }

        if (str_contains($promptText, 'roofing estimate')) {
            return str_contains($answerText, 'roofing estimate') && str_contains($answerText, 'req-018')
                ? null
                : 'wrong_request_history_roofing';
        }

        if (str_contains($promptText, 'egg protein note')) {
            return preg_match('/\b(did not find|didn.t find|no earlier|none|could not find|cannot find)\b/u', $answerText)
                ? null
                : 'wrong_request_history_egg_protein';
        }

        return null;
    }

    private function promptLooksLikeMemoryRecall(string $promptText): bool
    {
        return str_contains($promptText, 'what did you just save')
            || str_contains($promptText, 'what did you save about')
            || str_contains($promptText, 'what do you remember about')
            || str_contains($promptText, 'what did i tell you about')
            || str_contains($promptText, 'what did i say about');
    }

    private function specificMemoryRecallFailure(string $promptText, string $answerText): ?string
    {
        if (! $this->promptLooksLikeMemoryRecall($promptText)) {
            return null;
        }

        if (str_contains($promptText, 'errand updates')) {
            return str_contains($answerText, 'concise') && str_contains($answerText, 'status') && str_contains($answerText, 'updates')
                ? null
                : 'wrong_memory_recall_errand_updates';
        }

        return null;
    }

    private function answerAsksUsefulClarifyingQuestion(string $answerText): bool
    {
        return str_contains($answerText, 'which ')
            && (
                str_contains($answerText, 'do you mean')
                || str_contains($answerText, 'which one')
                || str_contains($answerText, 'which city')
                || str_contains($answerText, 'which location')
            );
    }

    /**
     * @return list<string>
     */
    private function workItemQualityFailures(AssistantRun $run): array
    {
        $run->loadMissing('userMessage');
        $userMessage = $run->userMessage;
        if (! $userMessage instanceof ConversationMessage) {
            return [];
        }

        $prompt = str((string) $userMessage->content)->lower()->squish()->toString();
        if (! $this->promptLooksLikeWriteRequest($prompt)) {
            return [];
        }

        $events = ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where(function ($query) use ($userMessage): void {
                $query->where('payload->message_id', $userMessage->id)
                    ->orWhere('payload->user_message_id', $userMessage->id);
            })
            ->orderBy('id')
            ->get();

        $planned = $events
            ->filter(fn (ActivityEvent $event): bool => $event->event_type === 'assistant.work_item.planned')
            ->values();

        if ($planned->isEmpty()) {
            return ['work_items_missing_plans'];
        }

        $failures = [];
        $plannedIds = [];
        $orders = [];
        $labelsById = [];

        foreach ($planned as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $id = trim((string) data_get($payload, 'work_item_id', ''));
            $label = str((string) data_get($payload, 'label', data_get($payload, 'work_label', '')))
                ->squish()
                ->toString();
            $order = data_get($payload, 'work_order');

            if ($id === '') {
                $failures[] = 'work_item_missing_id';

                continue;
            }
            if (isset($plannedIds[$id])) {
                $failures[] = 'work_item_duplicate_plan:'.$id;
            }
            $plannedIds[$id] = true;

            if ($label === '') {
                $failures[] = 'work_item_empty_label:'.$id;
            } elseif ($this->workItemLabelLooksBad($label)) {
                $failures[] = 'work_item_bad_label:'.$id.':'.$label;
            }
            $labelsById[$id] = $label;

            if (! is_numeric($order)) {
                $failures[] = 'work_item_missing_order:'.$id;
            } else {
                $numericOrder = (int) $order;
                if (isset($orders[$numericOrder])) {
                    $failures[] = 'work_item_duplicate_order:'.$numericOrder;
                }
                $orders[$numericOrder] = true;
            }
        }

        foreach (array_keys($plannedIds) as $id) {
            $completed = $events->contains(function (ActivityEvent $event) use ($id): bool {
                $payload = is_array($event->payload) ? $event->payload : [];

                return data_get($payload, 'work_item_id') === $id
                    && $event->event_type !== 'assistant.work_item.planned'
                    && $event->event_type !== 'runtime.planner_action_started'
                    && in_array($event->status, ['succeeded', 'completed', 'recorded'], true);
            });

            if (! $completed) {
                $failures[] = 'work_item_not_completed:'.$id.':'.($labelsById[$id] ?? '');
            }
        }

        $completionEvents = $events->filter(function (ActivityEvent $event): bool {
            $payload = is_array($event->payload) ? $event->payload : [];

            return filled(data_get($payload, 'work_item_id'))
                && $event->event_type !== 'assistant.work_item.planned'
                && $event->event_type !== 'runtime.planner_action_started';
        });

        foreach ($completionEvents as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $id = (string) data_get($payload, 'work_item_id');
            if (! isset($plannedIds[$id])) {
                $failures[] = 'work_item_orphan_completion:'.$id;
            }

            $label = str((string) data_get($payload, 'work_label', ''))->squish()->toString();
            if ($label !== '' && isset($labelsById[$id]) && $labelsById[$id] !== '' && $label !== $labelsById[$id]) {
                $failures[] = 'work_item_label_changed:'.$id;
            }
        }

        return array_values(array_unique($failures));
    }

    private function workItemLabelLooksBad(string $label): bool
    {
        $normalized = str($label)->lower()->squish()->toString();

        if (mb_strlen($label) > 96) {
            return true;
        }

        return (bool) preg_match('/\b(i need to|can you|could you|please|lets?|let\'s|for later this|create later for|task vacuum house)\b/u', $normalized);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return list<string>
     */
    private function followupStateFailures(ConversationSession $session, array $scenario): array
    {
        $assertions = is_array($scenario['assertions'] ?? null) ? $scenario['assertions'] : [];
        $failures = [];

        foreach ((array) ($assertions['calendar_title_counts'] ?? []) as $title => $expectedCount) {
            $actual = CalendarEvent::where('conversation_session_id', $session->id)
                ->whereRaw('lower(title) = ?', [strtolower((string) $title)])
                ->count();
            if ($actual !== (int) $expectedCount) {
                $failures[] = 'calendar_count:'.$title.':'.$actual.'/'.$expectedCount;
            }
        }

        foreach ((array) ($assertions['note_title_counts'] ?? []) as $title => $expectedCount) {
            $actual = $this->notesCreatedInSession($session)
                ->filter(fn (Note $note): bool => strcasecmp((string) $note->title, (string) $title) === 0)
                ->count();
            if ($actual !== (int) $expectedCount) {
                $failures[] = 'note_count:'.$title.':'.$actual.'/'.$expectedCount;
            }
        }

        foreach ((array) ($assertions['reminder_title_contains_counts'] ?? []) as $title => $expectedCount) {
            $needle = strtolower((string) $title);
            $actual = Reminder::where('conversation_session_id', $session->id)
                ->get()
                ->filter(fn (Reminder $reminder): bool => str_contains(strtolower((string) $reminder->title), $needle))
                ->count();
            if ($actual !== (int) $expectedCount) {
                $failures[] = 'reminder_count:'.$title.':'.$actual.'/'.$expectedCount;
            }
        }

        foreach ((array) ($assertions['minimum_reminder_title_contains_counts'] ?? []) as $title => $expectedCount) {
            $needle = strtolower((string) $title);
            $actual = Reminder::where('conversation_session_id', $session->id)
                ->get()
                ->filter(fn (Reminder $reminder): bool => str_contains(strtolower((string) $reminder->title), $needle))
                ->count();
            if ($actual < (int) $expectedCount) {
                $failures[] = 'reminder_min:'.$title.':'.$actual.'/'.$expectedCount;
            }
        }

        foreach ((array) ($assertions['memory_contains'] ?? []) as $needle) {
            $memoryExists = MemoryItem::where('user_id', $session->user_id)
                ->where('source_type', 'assistant_tool')
                ->where('source_id', $session->id)
                ->get()
                ->contains(fn (MemoryItem $item): bool => str_contains(strtolower((string) $item->content), strtolower((string) $needle)));
            if (! $memoryExists) {
                $failures[] = 'memory_missing:'.$needle;
            }
        }

        return array_values(array_unique($failures));
    }

    private function notesCreatedInSession(ConversationSession $session)
    {
        $noteIds = ActivityEvent::where('conversation_session_id', $session->id)
            ->where('event_type', 'assistant.note.created')
            ->get()
            ->map(fn (ActivityEvent $event): mixed => data_get($event->payload ?? [], 'note_id'))
            ->filter()
            ->unique()
            ->values();

        return $noteIds->isEmpty()
            ? collect()
            : Note::withTrashed()->whereIn('id', $noteIds)->get();
    }

    private function cleanup(User $user, string $suiteId): void
    {
        $sessionIds = ConversationSession::query()
            ->where('user_id', $user->id)
            ->where('metadata->suite_id', $suiteId)
            ->pluck('id');

        if ($sessionIds->isEmpty()) {
            return;
        }

        $activityEvents = ActivityEvent::whereIn('conversation_session_id', $sessionIds)->get();
        $noteIds = $activityEvents
            ->map(fn (ActivityEvent $event): mixed => data_get($event->payload ?? [], 'note_id'))
            ->filter()
            ->unique()
            ->values();
        $memoryItemIds = $activityEvents
            ->map(fn (ActivityEvent $event): mixed => data_get($event->payload ?? [], 'memory_item_id'))
            ->filter()
            ->unique()
            ->values();

        AiUsageLog::whereIn('conversation_session_id', $sessionIds)->delete();
        MemoryEvent::whereIn('conversation_session_id', $sessionIds)->delete();
        if ($memoryItemIds->isNotEmpty()) {
            MemoryItem::withTrashed()->whereIn('id', $memoryItemIds)->forceDelete();
        }
        MemoryItem::withTrashed()
            ->where('user_id', $user->id)
            ->where('source_type', 'assistant_tool')
            ->whereIn('source_id', $sessionIds)
            ->forceDelete();
        if ($noteIds->isNotEmpty()) {
            Note::withTrashed()->whereIn('id', $noteIds)->forceDelete();
        }
        CalendarEvent::whereIn('conversation_session_id', $sessionIds)->delete();
        Task::whereIn('conversation_session_id', $sessionIds)->delete();
        Reminder::whereIn('conversation_session_id', $sessionIds)->delete();
        ActivityEvent::whereIn('conversation_session_id', $sessionIds)->delete();
        AssistantRun::whereIn('conversation_session_id', $sessionIds)->delete();
        ConversationMessage::whereIn('conversation_session_id', $sessionIds)->delete();
        ConversationSession::whereIn('id', $sessionIds)->delete();
    }

    private function resetSmokeUserData(User $user): void
    {
        $sessionIds = ConversationSession::where('user_id', $user->id)->pluck('id');

        AiUsageLog::where('user_id', $user->id)->delete();
        MemoryEvent::where('user_id', $user->id)->delete();
        ActivityEvent::where('user_id', $user->id)->delete();
        AssistantRun::where('user_id', $user->id)->delete();
        ConversationMessage::where('user_id', $user->id)->delete();
        CalendarEvent::where('user_id', $user->id)->delete();
        Task::where('user_id', $user->id)->delete();
        Reminder::where('user_id', $user->id)->delete();
        Note::withTrashed()->where('user_id', $user->id)->forceDelete();
        MemoryItem::withTrashed()->where('user_id', $user->id)->forceDelete();
        if ($sessionIds->isNotEmpty()) {
            ConversationSession::whereIn('id', $sessionIds)->delete();
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  list<string>  $qualityFailures
     * @return array{
     *     class:string,
     *     first_response_ms:int,
     *     completion_target_ms:int,
     *     first_planned_work_ms:int|null,
     *     dashboard_freshness_ms:int|null,
     *     failures:list<string>,
     *     meets_kpi:bool
     * }
     */
    private function benchmarkMetricsForRun(string $prompt, AssistantRun $run, int $firstResponseMs, array $qualityFailures): array
    {
        $class = $this->promptBenchmarkClass($prompt);
        $completionTargetMs = $class === 'general_question' ? 3000 : 10000;
        $durationMs = $run->created_at && $run->updated_at
            ? (int) $run->created_at->diffInMilliseconds($run->updated_at, true)
            : null;
        $firstPlannedWorkMs = $this->firstPlannedWorkMs($run);
        $dashboardFreshnessMs = $this->dashboardFreshnessMs($run);
        $progressFailures = $this->progressTransparencyFailures($run, $class);
        $failures = [];

        if ($firstResponseMs > 3000) {
            $failures[] = 'first_response_over_3s';
        }

        if ($durationMs === null || $durationMs > $completionTargetMs) {
            $failures[] = 'completion_over_target';
        }

        if ($dashboardFreshnessMs !== null && $dashboardFreshnessMs > 1000) {
            $failures[] = 'dashboard_freshness_over_1s';
        }

        $failures = array_values(array_unique(array_merge($failures, $progressFailures)));

        return [
            'class' => $class,
            'first_response_ms' => $firstResponseMs,
            'completion_target_ms' => $completionTargetMs,
            'first_planned_work_ms' => $firstPlannedWorkMs,
            'dashboard_freshness_ms' => $dashboardFreshnessMs,
            'failures' => $failures,
            'meets_kpi' => $failures === [] && $qualityFailures === [],
        ];
    }

    private function promptBenchmarkClass(string $prompt): string
    {
        $promptText = str($prompt)->lower()->squish()->toString();

        if ($this->promptLooksLikeCapabilityQuestion($promptText)) {
            return 'general_question';
        }

        if ($this->promptLooksLikeWriteRequest($promptText)) {
            return $this->promptLooksMultiStep($promptText) ? 'complex_crud' : 'simple_crud';
        }

        if ($this->promptLooksLikeExternalLookup($promptText) || $this->promptLooksLikePlacesLookup($promptText)) {
            return 'external_lookup';
        }

        if ($this->promptLooksLikeDayContextRequest($promptText) || $this->promptLooksLikeRequestHistoryRecall($promptText) || $this->promptLooksLikeMemoryRecall($promptText)) {
            return 'app_context_lookup';
        }

        return 'general_question';
    }

    private function promptLooksLikeCapabilityQuestion(string $promptText): bool
    {
        $text = preg_replace('/^kpi-\d{3}:\s*|^req-\d{3}:\s*/u', '', $promptText) ?: $promptText;
        $text = str(str_replace('’', "'", $text))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        if (
            ! preg_match('/\?$/u', $text)
            && ! preg_match('/^(can|could|would|will|do|does|are|is)\b/u', $text)
        ) {
            return false;
        }

        if (preg_match('/\b(that says|saying|called|titled|named|at\s+\d|on\s+\d|tomorrow|today|tonight|this\s+(morning|afternoon|evening|week|month)|next\s+\w+|from\s+\d|to\s+\d|\d{1,2}:\d{2}|for\s+(tomorrow|today|tonight|next|monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/u', $text)) {
            return false;
        }

        return (bool) preg_match(
            '/^(can|could|would|will|do|does|are|is)\s+(you|bean)\b.{0,120}\b(create|add|make|schedule|book|set|update|change|move|reschedule|delete|remove|cancel|complete|mark|remember|forget|look up|find|search|sync|manage|handle|help)\b.{0,80}\??$/u',
            $text
        );
    }

    private function promptLooksMultiStep(string $promptText): bool
    {
        return substr_count($promptText, ' and ') >= 2
            || str_contains($promptText, ' then ')
            || str_contains($promptText, ' after that ')
            || str_contains($promptText, ' as well ')
            || str_contains($promptText, 'also ')
            || str_contains($promptText, ',');
    }

    private function promptLooksLikeExternalLookup(string $promptText): bool
    {
        return (bool) preg_match('/\b(weather|forecast|traffic|news|headline|headlines|flight|flights|hotel|hotels|airfare|ticket|tickets|stock|stocks|market|markets|sports|score|scores|nearest|closest|nearby|current|right now|latest)\b/u', $promptText);
    }

    private function firstPlannedWorkMs(AssistantRun $run): ?int
    {
        if (! $run->created_at) {
            return null;
        }

        $messageId = (int) $run->user_message_id;
        $event = ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('event_type', 'assistant.work_item.planned')
            ->when($messageId > 0, function ($query) use ($messageId): void {
                $query->where(function ($query) use ($messageId): void {
                    $query->where('payload->message_id', $messageId)
                        ->orWhere('payload->user_message_id', $messageId);
                });
            })
            ->orderBy('id')
            ->first();

        return $event?->created_at
            ? (int) $run->created_at->diffInMilliseconds($event->created_at, true)
            : null;
    }

    private function dashboardFreshnessMs(AssistantRun $run): ?int
    {
        $events = $this->dashboardMutationEventsForRun($run);
        if ($events->isEmpty()) {
            return null;
        }

        $worstMs = 0;
        foreach ($events as $event) {
            $visibleAt = $this->dashboardResourceVisibleAt($event) ?? $event->created_at;
            if (! $event->created_at || ! $visibleAt) {
                continue;
            }
            $worstMs = max($worstMs, max(0, (int) $event->created_at->diffInMilliseconds($visibleAt, false)));
        }

        return $worstMs;
    }

    private function dashboardMutationEventsForRun(AssistantRun $run)
    {
        $messageId = (int) $run->user_message_id;

        return ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->whereIn('status', ['succeeded', 'completed', 'recorded'])
            ->where(function ($query): void {
                $query->where('event_type', 'like', 'assistant.task.%')
                    ->orWhere('event_type', 'like', 'assistant.reminder.%')
                    ->orWhere('event_type', 'like', 'assistant.calendar_event.%')
                    ->orWhere('event_type', 'like', 'assistant.note.%')
                    ->orWhere('event_type', 'like', 'assistant.note_folder.%')
                    ->orWhere('event_type', 'like', 'assistant.memory.%');
            })
            ->when($messageId > 0, function ($query) use ($messageId): void {
                $query->where(function ($query) use ($messageId): void {
                    $query->where('payload->source_message_id', $messageId)
                        ->orWhere('payload->message_id', $messageId)
                        ->orWhere('payload->user_message_id', $messageId)
                        ->orWhere('payload->request_message_id', $messageId)
                        ->orWhere('payload->work_item_id', 'like', 'crud-plan-'.$messageId.'-%');
                });
            })
            ->orderBy('id')
            ->get();
    }

    private function dashboardResourceVisibleAt(ActivityEvent $event): mixed
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $type = (string) $event->event_type;
        $id = data_get($payload, 'task_id')
            ?? data_get($payload, 'reminder_id')
            ?? data_get($payload, 'calendar_event_id')
            ?? data_get($payload, 'event_id')
            ?? data_get($payload, 'note_id')
            ?? data_get($payload, 'memory_item_id');

        if (! $id) {
            return $event->created_at;
        }

        $model = match (true) {
            str_contains($type, '.task.') => Task::find($id),
            str_contains($type, '.reminder.') => Reminder::find($id),
            str_contains($type, '.calendar_event.') => CalendarEvent::find($id),
            str_contains($type, '.note.') => Note::withTrashed()->find($id),
            str_contains($type, '.memory.') => MemoryItem::withTrashed()->find($id),
            default => null,
        };

        return $model?->updated_at ?? $model?->created_at ?? $event->created_at;
    }

    /**
     * @return list<string>
     */
    private function progressTransparencyFailures(AssistantRun $run, string $class): array
    {
        if (in_array($class, ['simple_crud', 'complex_crud'], true)) {
            return $this->workItemQualityFailures($run);
        }

        if (in_array($class, ['external_lookup', 'app_context_lookup'], true)) {
            $messageId = (int) $run->user_message_id;
            $hasRuntimeProgress = ActivityEvent::query()
                ->where('conversation_session_id', $run->conversation_session_id)
                ->when($messageId > 0, function ($query) use ($messageId): void {
                    $query->where(function ($query) use ($messageId): void {
                        $query->where('payload->message_id', $messageId)
                            ->orWhereNull('payload->message_id');
                    });
                })
                ->whereIn('event_type', ['runtime.run_queued', 'runtime.tool_model_started', 'runtime.tool_model_completed', 'runtime.message_completed'])
                ->exists();

            return $hasRuntimeProgress ? [] : ['runtime_progress_missing'];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function kpiSummary(array $results): array
    {
        $count = max(1, count($results));
        $dashboardApplicable = collect($results)->filter(fn (array $result): bool => $result['dashboard_freshness_ms'] !== null)->values();

        return [
            'targets' => [
                'first_meaningful_response_under_ms' => 3000,
                'completion_under_ms' => ['general_question' => 3000, 'external_lookup_or_complex_action' => 10000],
                'action_success_without_user_correction_rate' => 0.98,
                'progress_transparency_accuracy_rate' => 0.98,
                'dashboard_freshness_under_ms' => 1000,
            ],
            'first_meaningful_response' => $this->rateMetric($results, fn (array $result): bool => ($result['first_response_ms'] ?? PHP_INT_MAX) <= 3000),
            'completed_under_target' => $this->rateMetric($results, fn (array $result): bool => ($result['duration_ms'] ?? PHP_INT_MAX) <= ($result['completion_target_ms'] ?? 10000)),
            'action_success_without_user_correction' => $this->rateMetric($results, fn (array $result): bool => ($result['status'] ?? null) === 'completed' && ($result['quality_failures'] ?? []) === [] && ! $this->containsFailureCopy((string) ($result['assistant'] ?? ''))),
            'progress_transparency_accuracy' => $this->rateMetric($results, fn (array $result): bool => ! collect($result['kpi_failures'] ?? [])->contains(fn (string $failure): bool => str_starts_with($failure, 'work_item_') || $failure === 'runtime_progress_missing')),
            'dashboard_freshness' => $dashboardApplicable->isEmpty()
                ? ['applicable' => 0, 'passed' => 0, 'rate' => null, 'p95_ms' => null, 'max_ms' => null]
                : $this->rateMetric($dashboardApplicable->all(), fn (array $result): bool => ($result['dashboard_freshness_ms'] ?? PHP_INT_MAX) <= 1000, 'dashboard_freshness_ms'),
            'sample_size' => $count,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{applicable:int,passed:int,rate:float,p95_ms:int|null,max_ms:int|null}
     */
    private function rateMetric(array $results, callable $passes, ?string $latencyField = null): array
    {
        $applicable = count($results);
        $passed = collect($results)->filter(fn (array $result): bool => (bool) $passes($result))->count();
        $latencies = $latencyField
            ? collect($results)->map(fn (array $result): mixed => $result[$latencyField] ?? null)->filter(fn (mixed $value): bool => is_numeric($value))->map(fn (mixed $value): int => (int) $value)->sort()->values()
            : collect();

        return [
            'applicable' => $applicable,
            'passed' => $passed,
            'rate' => $applicable > 0 ? round($passed / $applicable, 4) : 0.0,
            'p95_ms' => $latencies->isEmpty() ? null : $latencies->get((int) floor(($latencies->count() - 1) * 0.95)),
            'max_ms' => $latencies->isEmpty() ? null : $latencies->max(),
        ];
    }

    /**
     * @param  array<string, mixed>  $kpis
     */
    private function kpiSummaryMeetsTargets(array $kpis): bool
    {
        return (float) data_get($kpis, 'first_meaningful_response.rate', 0) >= 0.98
            && (float) data_get($kpis, 'completed_under_target.rate', 0) >= 0.98
            && (float) data_get($kpis, 'action_success_without_user_correction.rate', 0) >= 0.98
            && (float) data_get($kpis, 'progress_transparency_accuracy.rate', 0) >= 0.98
            && (
                data_get($kpis, 'dashboard_freshness.rate') === null
                || (float) data_get($kpis, 'dashboard_freshness.rate', 0) >= 0.98
            );
    }

    /**
     * @return list<array{name: string, steps: list<string>, assertions: array<string, mixed>}>
     */
    private function followupScenarios(): array
    {
        return [
            [
                'name' => 'capability_question_then_calendar_action',
                'steps' => [
                    'Can you create calendar events?',
                    'Great, create one called Test Focus Block tomorrow at 9am for 45 minutes.',
                ],
                'assertions' => [
                    'calendar_title_counts' => [
                        'Test Focus Block' => 1,
                    ],
                ],
            ],
            [
                'name' => 'event_plan_followup_without_duplicate_workout',
                'steps' => [
                    'Add a workout today from 5:30pm to 6:30pm. After you add it, ask whether I want grocery shopping and cooking dinner added after it.',
                    'Yes please. Add grocery shopping for 45 minutes after the workout, then cooking dinner for 30 minutes after grocery shopping, and create 15-minute reminders for both.',
                ],
                'assertions' => [
                    'calendar_title_counts' => [
                        'Workout' => 1,
                        'Grocery shopping' => 1,
                        'Cook dinner' => 1,
                    ],
                    'minimum_reminder_title_contains_counts' => [
                        'grocery' => 1,
                        'cook' => 1,
                    ],
                ],
            ],
            [
                'name' => 'note_followup_reminder_without_duplicate_note',
                'steps' => [
                    'Create a note called Boiled Egg Directions with three steps for boiling an egg.',
                    'Also remind me tomorrow at 8am to review the Boiled Egg Directions note.',
                ],
                'assertions' => [
                    'note_title_counts' => [
                        'Boiled Egg Directions' => 1,
                    ],
                    'minimum_reminder_title_contains_counts' => [
                        'boiled egg directions' => 1,
                    ],
                ],
            ],
            [
                'name' => 'move_event_and_delete_related_reminder',
                'steps' => [
                    'Add a workout today from 5pm to 6pm, grocery shopping today from 6pm to 6:45pm, and a reminder 15 minutes before grocery shopping.',
                    'Move grocery shopping to start after the workout at 6:15pm and delete the grocery shopping reminder.',
                ],
                'assertions' => [
                    'calendar_title_counts' => [
                        'Workout' => 1,
                        'Grocery shopping' => 1,
                    ],
                    'reminder_title_contains_counts' => [
                        'grocery shopping' => 0,
                    ],
                ],
            ],
            [
                'name' => 'memory_save_then_recall',
                'steps' => [
                    'Remember that I prefer concise status updates for errands.',
                    'What did you just save about errand updates?',
                ],
                'assertions' => [
                    'memory_contains' => [
                        'concise status updates',
                    ],
                ],
            ],
            [
                'name' => 'lookup_then_save_note_from_context',
                'steps' => [
                    'How many grams of protein are in a boiled egg? Keep it brief.',
                    'Save that as a note called Egg Protein Note.',
                ],
                'assertions' => [
                    'note_title_counts' => [
                        'Egg Protein Note' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function kpiPrompts(): array
    {
        return [
            'KPI-001: Can you create calendar events for me?',
            'KPI-002: What can you help me manage in HeyBean?',
            'KPI-003: What is the difference between a task and a reminder?',
            'KPI-004: Can you help me organize notes into folders?',
            'KPI-005: How should I think about using Bean for my day?',
            'KPI-006: What do I have coming up today?',
            'KPI-007: What tasks, reminders, and events are left today?',
            'KPI-008: Review today and suggest my next useful step.',
            'KPI-009: What is on my calendar and reminders today?',
            'KPI-010: What do you remember about my preferences?',
            'KPI-011: Create a calendar event called KPI Focus Block tomorrow at 9am for 30 minutes.',
            'KPI-012: Add a task to review KPI notes tomorrow morning.',
            'KPI-013: Set a reminder tomorrow at 8am to check the KPI dashboard.',
            'KPI-014: Create a note called KPI Test Note with three short bullets.',
            'KPI-015: Remember that I prefer concise KPI updates.',
            'KPI-016: Create a calendar event tomorrow at 10am called KPI Planning, add a task to prepare the agenda, and remind me 30 minutes before.',
            'KPI-017: Create a note called KPI Errand Plan, pin it, and remind me tomorrow at 4pm to review it.',
            'KPI-018: Add a workout tomorrow from 5pm to 6pm, then add grocery shopping after it for 45 minutes.',
            'KPI-019: Create a task to organize receipts tomorrow, a reminder 20 minutes before, and a note called Receipt Checklist.',
            'KPI-020: Plan Friday morning with a calendar focus block at 9am, task to gather notes, and reminder Thursday afternoon.',
            'KPI-021: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.',
            'KPI-022: Find the weather for tomorrow in Tampa and suggest morning or evening errands.',
            'KPI-023: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'KPI-024: Find the closest Starbucks to 32820 and give me the address.',
            'KPI-025: Find the nearest Home Depot to 32820 and tell me the address quickly.',
            'KPI-026: Remember that KPI errands should be short and practical, then tell me what you saved.',
            'KPI-027: What did you just save about KPI errands?',
            'KPI-028: Add three calendar events: KPI Dentist 7/15 at 3pm, KPI Oil Change 7/16 at 8am, and KPI Review 7/17 at 5pm.',
            'KPI-029: Create a home reset plan for Saturday: calendar block at 10am, task to gather supplies, reminder Friday at 5pm, and a note called KPI Saturday Reset.',
            'KPI-030: What request did I make about KPI Dentist earlier in this smoke run?',
        ];
    }

    /**
     * @return list<string>
     */
    private function prompts(): array
    {
        $templates = array_merge(
            [
                'Plan the rest of my afternoon: add a 45 minute workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Set up tomorrow morning: calendar focus block at 9am for 60 minutes, task to prep the agenda, reminder 30 minutes before, and a note called Agenda Prep.',
                'Create a home reset plan for Saturday: calendar block at 10am, task to gather supplies, reminder Friday at 5pm, and a checklist note called Saturday Reset.',
                'Organize a client follow-up: calendar call next Tuesday at 2pm, task to review notes, reminder one hour before, and a note called Client Follow-up Questions.',
                'Build a car maintenance workflow: calendar block next Friday at 8am, task to check tire pressure, reminder the night before, and a note called Car Maintenance Checklist.',
                'Plan an admin cleanup: calendar block tomorrow at 1pm, tasks for receipts and subscription review, reminder 20 minutes before, and a note called Admin Cleanup.',
                'Prepare a family dinner plan for Sunday: calendar event at 6pm, task to buy ingredients, reminder Sunday morning, and a note with a short grocery list.',
                'Set up a project review workflow: calendar block Monday at 11am, task to collect open questions, reminder Monday at 10am, and a note called Project Review.',
                'Create a travel prep workflow: calendar block next Thursday at 7pm, task to pack chargers, reminder the day before, and a note called Travel Prep Checklist.',
                'Plan a budget check: calendar block tomorrow at 4pm, task to compare bills, reminder 15 minutes before, and a note called Budget Check Notes.',
            ],
            [
                'Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm.',
                'Add three calendar events: 7/10 Strategy review at 101 Main St at 9am, 7/16 Budget cleanup at 45 Pine Rd at 1pm, and 7/21 Family dinner at 6pm.',
                'Add three calendar events: 7/11 Oil change at 500 Service Rd at 8am, 7/17 Dentist consult at 3pm, and 7/22 School pickup at 2pm.',
                'Add three calendar events: 7/12 Client check-in at 10am, 7/18 Warehouse walkthrough at 77 Industrial Dr at 4pm, and 7/23 Call Mom at 7pm.',
                'Add three calendar events: 7/13 Proposal writing at 9am, 7/20 Neighborhood meeting at 123 Oak Ave at 6pm, and 7/24 Vet appointment at 11am.',
                'Add three calendar events: 7/14 Vendor call at 8am, 7/21 Equipment pickup at 44 Lake Rd at 12pm, and 7/25 Date night at 7pm.',
                'Add three calendar events: 7/15 Team planning at 10am, 7/22 Parts run at 900 Auto Way at 2pm, and 7/26 Meal prep at 5pm.',
                'Add three calendar events: 7/16 Roofing estimate at 700 North St at 9am, 7/23 Insurance call at 1pm, and 7/27 Workout review at 4pm.',
                'Add three calendar events: 7/17 Tax packet at 11am, 7/24 Home inspection at 55 Cedar Ln at 3pm, and 7/28 Grocery planning at 6pm.',
                'Add three calendar events: 7/18 Breakfast meeting at 8am, 7/25 Shop cleanup at 190 Garage Dr at 2pm, and 7/29 Weekly review at 5pm.',
            ],
            [
                'Create a task to review insurance paperwork tomorrow morning, remind me 30 minutes before, and save a note with the documents I should bring.',
                'Create a task to organize July receipts tomorrow morning, remind me 20 minutes before, and save a note called July Receipt Checklist.',
                'Create a task to call the roofing contractor tomorrow morning, remind me 15 minutes before, and save a note called Roofing Call Notes.',
                'Create a task to prep the meeting agenda tomorrow morning, remind me 45 minutes before, and save a note called Meeting Prep Notes.',
                'Create a task to compare service quotes tomorrow morning, remind me 30 minutes before, and save a note called Quote Comparison Notes.',
                'Create a task to clean out the car tomorrow morning, remind me 25 minutes before, and save a note called Car Cleanup List.',
                'Create a task to check subscription renewals tomorrow morning, remind me 10 minutes before, and save a note called Renewal Review.',
                'Create a task to review the weekly budget tomorrow morning, remind me 30 minutes before, and save a note called Budget Review Notes.',
                'Create a task to plan Saturday errands tomorrow morning, remind me 20 minutes before, and save a note called Errand Plan.',
                'Create a task to prepare project files tomorrow morning, remind me 30 minutes before, and save a note called Project File Checklist.',
            ],
            [
                'Create a note called Quick Dinner Ideas with three fast meals, pin it, and add a reminder tomorrow at 4pm to pick one.',
                'Create a note called Weekend Errand Plan with a quick checklist, pin it, and add a reminder tomorrow at 10am to review it.',
                'Create a note called Car Maintenance Ideas with a short checklist, pin it, and add a reminder tomorrow at 8am to choose one.',
                'Create a note called House Reset Plan with three practical steps, pin it, and add a reminder tomorrow at 9am to start it.',
                'Create a note called Simple Lunch Ideas with three fast meals, pin it, and add a reminder tomorrow at 11am to pick one.',
                'Create a note called Project Cleanup Plan with a simple checklist, pin it, and add a reminder tomorrow at 2pm to review it.',
                'Create a note called Morning Routine Tuneup with three ideas, pin it, and add a reminder tomorrow at 7am to try one.',
                'Create a note called Gift Ideas List with a few practical ideas, pin it, and add a reminder tomorrow at 6pm to update it.',
                'Create a note called Travel Prep Notes with a compact checklist, pin it, and add a reminder tomorrow at 5pm to review it.',
                'Create a note called Follow Up List with three people to contact, pin it, and add a reminder tomorrow at 3pm to pick one.',
            ],
            [
                'Move my next workout event to 5:30pm if there is one today, then create a reminder 15 minutes before it.',
                'Move my next workout event to 6:00pm if there is one today, then create a reminder 20 minutes before it.',
                'Move my next workout event to 5:45pm if there is one today, then create a reminder 10 minutes before it.',
                'Move my next workout event to 6:15pm if there is one today, then create a reminder 15 minutes before it.',
                'Move my next workout event to 5:15pm if there is one today, then create a reminder 25 minutes before it.',
                'Move my next workout event to 6:30pm if there is one today, then create a reminder 30 minutes before it.',
                'Move my next workout event to 4:45pm if there is one today, then create a reminder 15 minutes before it.',
                'Move my next workout event to 7:00pm if there is one today, then create a reminder 20 minutes before it.',
                'Move my next workout event to 4:30pm if there is one today, then create a reminder 10 minutes before it.',
                'Move my next workout event to 7:15pm if there is one today, then create a reminder 15 minutes before it.',
            ],
            [
                'Create a project follow-up workflow: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the garage plan: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the budget cleanup: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the contractor quotes: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the family schedule: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the app review: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the shop cleanup: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the client list: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the weekly review: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
                'Create a project follow-up workflow for the household checklist: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
            ],
            [
                'Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
                'Find the weather for tomorrow in Tampa, then suggest whether errands should be morning or evening.',
                'Find the weather for tomorrow in Miami, then tell me if an outdoor lunch sounds reasonable.',
                'Find the weather for tomorrow in Jacksonville, then suggest whether to wash the car.',
                'Find the weather for tomorrow in Atlanta, then suggest whether to plan outdoor work.',
                'Find the weather for tomorrow in Charlotte, then suggest whether a walk after dinner makes sense.',
                'Find the weather for tomorrow in Nashville, then suggest whether to bring a light jacket.',
                'Find the weather for tomorrow in Dallas, then suggest whether yard work should be early.',
                'Find the weather for tomorrow in Denver, then suggest whether to plan an outdoor workout.',
                'Find the weather for tomorrow in Phoenix, then suggest whether afternoon errands are smart.',
            ],
            [
                'Find the nearest Wawa to 32820 and tell me the address quickly.',
                'Find the closest Wawa to ZIP code 32820 and give me the full street address.',
                'Find a nearby Wawa around 32820 and tell me the closest address.',
                'Find the nearest Starbucks to 32820 and tell me the address quickly.',
                'Find the closest Costco to 32820 and give me the full street address.',
                'Find the nearest Home Depot to 32820 and tell me the address quickly.',
                'Find the closest Target to 32820 and give me the full street address.',
                'Find the nearest Publix to 32820 and tell me the address quickly.',
                'Find the closest Lowe\'s to 32820 and give me the full street address.',
                'Find the nearest gas station to 32820 and tell me the address quickly.',
            ],
            [
                'Remember that I prefer short practical answers unless I ask for detail, then tell me what you saved.',
                'Remember that weekday mornings are best for admin work, then tell me what you saved.',
                'Remember that I prefer evening workouts when possible, then tell me what you saved.',
                'Remember that grocery reminders should be direct and brief, then tell me what you saved.',
                'Remember that family schedule items are high priority, then tell me what you saved.',
                'Remember that I like checklists for errands, then tell me what you saved.',
                'Remember that I prefer calendar blocks over vague tasks for deep work, then tell me what you saved.',
                'Remember that I want travel prep reminders at least a day early, then tell me what you saved.',
                'Remember that I prefer concise dinner ideas with common ingredients, then tell me what you saved.',
                'Remember that urgent home maintenance should be marked critical, then tell me what you saved.',
            ],
            [
                'What do I have coming up today, and if there is empty time after 5pm, suggest a simple plan.',
                'What is on my calendar and reminders today, and suggest a practical evening order.',
                'What tasks, reminders, and events are left today, and give me a concise plan.',
                'What do I have later today, and identify the next useful gap.',
                'Review today and suggest the simplest next three steps.',
            ],
            [
                'What did I ask for REQ-011? Answer with the exact request you find.',
                'What request did I make about Dr Chen Cardio earlier in this smoke run?',
                'Which request did I make about Roofing estimate? Include the REQ number.',
                'What was my earlier request about Egg Protein Note, if any? If there was none, say so clearly.',
                'What did I request in REQ-100? If it does not exist yet, say you cannot find that request.',
            ],
        );

        return collect($templates)
            ->take(100)
            ->values()
            ->map(fn (string $prompt, int $index): string => 'REQ-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).': '.$prompt)
            ->all();
    }
}
