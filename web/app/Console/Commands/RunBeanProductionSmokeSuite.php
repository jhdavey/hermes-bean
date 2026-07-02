<?php

namespace App\Console\Commands;

use App\Models\AssistantRun;
use App\Models\ActivityEvent;
use App\Models\AiUsageLog;
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
        {--scenario=single : Smoke scenario to run: single or followups}
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

        if ((string) $this->option('scenario') === 'followups') {
            return $this->runFollowupSuite($user, $workspace, $runs, $count, $timeout, $suiteId);
        }

        $this->info("Running {$count} Bean production smoke requests as {$user->email} in workspace {$workspace->id}.");
        $this->line("Suite: {$suiteId}");

        $results = [];
        $startedAt = microtime(true);

        foreach (array_slice($this->prompts(), 0, $count) as $index => $prompt) {
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

            $run = $this->waitForRun($queued['run'], $timeout);
            $assistant = $run->assistantMessage?->content ?? '';
            $durationMs = $run->created_at && $run->updated_at
                ? (int) $run->created_at->diffInMilliseconds($run->updated_at, true)
                : null;
            $qualityFailures = $this->assistantQualityFailures($prompt, $assistant);
            $failed = $run->status !== 'completed' || $this->containsFailureCopy($assistant) || $qualityFailures !== [];

            $results[] = [
                'case' => $case,
                'run_id' => $run->id,
                'session_id' => $session->id,
                'status' => $run->status,
                'duration_ms' => $durationMs,
                'failed' => $failed,
                'quality_failures' => $qualityFailures,
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
                str(($qualityFailures === [] ? '' : '['.implode(', ', $qualityFailures).'] ').($assistant ?: $run->error ?: 'No response'))->squish()->limit(150)->toString(),
            ));
        }

        $failed = collect($results)->where('failed', true)->values();
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $summary = [
            'suite_id' => $suiteId,
            'count' => count($results),
            'passed' => count($results) - $failed->count(),
            'failed' => $failed->count(),
            'elapsed_ms' => $elapsedMs,
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
                $qualityFailures = $this->assistantQualityFailures($prompt, $assistant);
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
            if (in_array($fresh->status, ['completed', 'failed', 'cancelled'], true)) {
                return $fresh;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        return app(AssistantRunService::class)->recoverStaleRun(
            AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($run->id),
            app(\App\Services\HermesRuntimeService::class),
        );
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

        return array_values(array_unique($failures));
    }

    private function promptLooksLikeWriteRequest(string $promptText): bool
    {
        if ($this->promptLooksLikeRequestHistoryRecall($promptText)) {
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
        if (str_contains($promptText, '32820') && str_contains($promptText, 'wawa')) {
            return str_contains($answerText, '16959') || str_contains($answerText, 'e colonial')
                ? null
                : 'wrong_wawa_32820';
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
    private function prompts(): array
    {
        $templates = array_merge(
            [
                'Plan the rest of my afternoon: add a 45 minute workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a focused home reset block: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan my evening reset: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a healthy night: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a productive after-work flow: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a quick errands evening: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a simple dinner prep evening: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a balanced evening routine: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a practical evening schedule: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
                'Plan a low-stress night: add a workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
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
