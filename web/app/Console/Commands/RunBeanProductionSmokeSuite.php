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
            'reached today\'s ai usage limit',
            'reached today’s ai usage limit',
            'reached today\'s external lookup usage limit',
            'reached today’s external lookup usage limit',
            'ai usage limit reached',
            'usage limit',
        ]);
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
                'What is still scheduled today, and tell me what I should do first.',
                'What is coming up today after lunch, and suggest a low-stress sequence.',
                'What do I need to remember today, and point out any tight timing.',
                'Look at today and tell me if my evening is overloaded.',
                'What remains today, and suggest one practical improvement to the plan.',
            ],
        );

        return collect($templates)
            ->take(100)
            ->values()
            ->map(fn (string $prompt, int $index): string => 'REQ-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).': '.$prompt)
            ->all();
    }
}
