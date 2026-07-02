<?php

namespace App\Console\Commands;

use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
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
            $failed = $run->status !== 'completed' || $this->containsFailureCopy($assistant);

            $results[] = [
                'case' => $case,
                'run_id' => $run->id,
                'session_id' => $session->id,
                'status' => $run->status,
                'duration_ms' => $durationMs,
                'failed' => $failed,
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
                str($assistant ?: $run->error ?: 'No response')->squish()->limit(110)->toString(),
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
        ]);
    }

    private function cleanup(User $user, string $suiteId): void
    {
        $sessionIds = ConversationSession::query()
            ->where('user_id', $user->id)
            ->where('metadata->suite_id', $suiteId)
            ->pluck('id');

        CalendarEvent::whereIn('conversation_session_id', $sessionIds)->delete();
        Task::whereIn('conversation_session_id', $sessionIds)->delete();
        Reminder::whereIn('conversation_session_id', $sessionIds)->delete();
    }

    private function resetSmokeUserData(User $user): void
    {
        CalendarEvent::where('user_id', $user->id)->delete();
        Task::where('user_id', $user->id)->delete();
        Reminder::where('user_id', $user->id)->delete();
        Note::where('user_id', $user->id)->delete();
        MemoryItem::where('user_id', $user->id)->delete();
    }

    /**
     * @return list<string>
     */
    private function prompts(): array
    {
        $templates = [
            'Plan the rest of my afternoon: add a 45 minute workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
            'Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm.',
            'Create a task to review insurance paperwork tomorrow morning, remind me 30 minutes before, and save a note with the documents I should bring.',
            'Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Find the nearest Wawa to 32820 and tell me the address quickly.',
            'Create a note called Quick Dinner Ideas with three fast meals, pin it, and add a reminder tomorrow at 4pm to pick one.',
            'Move my next workout event to 5:30pm if there is one today, then create a reminder 15 minutes before it.',
            'Create a project follow-up workflow: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
            'Remember that I prefer short practical answers unless I ask for detail, then tell me what you saved.',
            'What do I have coming up today, and if there is empty time after 5pm, suggest a simple plan.',
        ];

        $prompts = [];
        for ($i = 1; $i <= 100; $i++) {
            $prompts[] = 'REQ-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).': '.$templates[($i - 1) % count($templates)];
        }

        return $prompts;
    }
}
