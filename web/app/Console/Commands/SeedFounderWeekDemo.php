<?php

namespace App\Console\Commands;

use App\Models\BeanActivityEvent;
use App\Models\BeanConfirmationRequest;
use App\Models\BeanMessage;
use App\Models\BeanQualityTrace;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanToolCall;
use App\Models\BeanVoiceEvent;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedFounderWeekDemo extends Command
{
    protected $signature = 'demo:seed-founder-week
        {--email=harleydemo@email.com : Demo account email}
        {--password= : Demo account password; defaults to the local demo password}
        {--first-name=Harley : Demo account first name}
        {--timezone=America/New_York : Demo account timezone}
        {--force : Allow seeding when APP_ENV=production}';

    protected $description = 'Seed a polished founder demo account for a Bean weekly planning screen recording.';

    public function handle(WorkspaceService $workspaces): int
    {
        $email = Str::lower(trim((string) $this->option('email')));
        $passwordOption = (string) $this->option('password');
        $password = $passwordOption !== '' ? $passwordOption : 'password1234';
        $firstName = trim((string) $this->option('first-name')) ?: 'Harley';
        $timezone = trim((string) $this->option('timezone')) ?: 'America/New_York';

        if ($this->laravel->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing to seed the demo account in production without --force.');

            return self::FAILURE;
        }

        if ($this->laravel->environment('production') && $passwordOption === '') {
            $this->error('Production demo seeding requires an explicit --password value.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The demo email must be a valid email address.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 8) {
            $this->error('The demo password must be at least 8 characters.');

            return self::FAILURE;
        }

        $summary = DB::transaction(function () use ($email, $password, $firstName, $timezone, $workspaces): array {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $firstName,
                    'password' => $password,
                    'onboard_complete' => true,
                    'subscription_tier' => 'pro',
                    'subscription_status' => 'active',
                    'timezone' => $timezone,
                    'theme_mode' => 'light',
                    'command_center_label' => 'Harley HQ',
                    'notification_preferences' => [
                        'reminder_push' => true,
                        'reminder_email' => true,
                    ],
                ],
            );
            $user->forceFill([
                'email_verified_at' => now(),
                'onboard_complete' => true,
                'subscription_tier' => 'pro',
                'subscription_status' => 'active',
                'timezone' => $timezone,
            ])->save();

            $workspaceId = $workspaces->ensurePersonalWorkspaceForUser($user->refresh());
            $workspace = Workspace::findOrFail($workspaceId);
            $workspace->forceFill([
                'name' => "{$firstName}'s Launch Week",
                'metadata' => array_merge($workspace->metadata ?? [], ['demo_seed' => 'founder_week']),
            ])->save();
            $user->forceFill(['default_workspace_id' => $workspace->id])->save();

            $this->resetDemoData($user);
            $this->seedCalendar($user->refresh(), $workspace, $timezone);
            $this->seedTasks($user->refresh(), $workspace, $timezone);
            $this->seedReminders($user->refresh(), $workspace, $timezone);
            $this->seedMealPlanNote($user->refresh(), $workspace, $timezone);

            return [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'calendar_events' => CalendarEvent::where('user_id', $user->id)->count(),
                'tasks' => Task::where('user_id', $user->id)->count(),
                'reminders' => Reminder::where('user_id', $user->id)->count(),
                'notes' => Note::where('user_id', $user->id)->count(),
                'note_folders' => NoteFolder::where('user_id', $user->id)->count(),
            ];
        });

        $this->info('Founder week demo account seeded.');
        $this->line('Email: '.$email);
        $this->line('Password: configured');
        $this->line('User ID: '.$summary['user_id']);
        $this->line('Workspace ID: '.$summary['workspace_id']);
        $this->line('Calendar events: '.$summary['calendar_events']);
        $this->line('Tasks: '.$summary['tasks']);
        $this->line('Reminders: '.$summary['reminders']);
        $this->line('Notes: '.$summary['notes']);

        return self::SUCCESS;
    }

    private function resetDemoData(User $user): void
    {
        BeanToolCall::where('user_id', $user->id)->delete();
        BeanActivityEvent::where('user_id', $user->id)->delete();
        BeanConfirmationRequest::where('user_id', $user->id)->delete();
        BeanQualityTrace::where('user_id', $user->id)->delete();
        BeanVoiceEvent::where('user_id', $user->id)->delete();
        BeanMessage::where('user_id', $user->id)->delete();
        BeanRun::where('user_id', $user->id)->delete();
        BeanSession::where('user_id', $user->id)->delete();

        Note::withTrashed()->where('user_id', $user->id)->forceDelete();
        NoteFolder::where('user_id', $user->id)->delete();
        Reminder::where('user_id', $user->id)->delete();
        Task::where('user_id', $user->id)->delete();
        CalendarEvent::where('user_id', $user->id)->delete();
    }

    private function seedCalendar(User $user, Workspace $workspace, string $timezone): void
    {
        $week = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->startOfDay();

        $event = function (int $dayOffset, string $start, string $end, string $title, string $category, string $description, ?string $location = null, string $color = '#111827') use ($user, $workspace, $week): void {
            [$startHour, $startMinute] = array_map('intval', explode(':', $start));
            [$endHour, $endMinute] = array_map('intval', explode(':', $end));
            $startsAt = $week->copy()->addDays($dayOffset)->setTime($startHour, $startMinute, 0);
            $endsAt = $week->copy()->addDays($dayOffset)->setTime($endHour, $endMinute, 0);

            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'description' => $description,
                'location' => $location,
                'category' => $category,
                'color' => $color,
                'is_critical' => in_array($category, ['Bean', 'Client', 'Family'], true),
                'starts_at' => $startsAt->copy()->utc(),
                'ends_at' => $endsAt->copy()->utc(),
                'status' => 'scheduled',
                'metadata' => ['demo_seed' => 'founder_week'],
            ]);
        };

        // Monday
        $event(0, '06:30', '07:30', 'Strength workout — push day', 'Health', 'Six-day training rhythm before work.', 'Gym', '#16a34a');
        $event(0, '08:00', '09:00', 'Weekly planning with Bean', 'Planning', 'Review calendar, tasks, reminders, meal plan, and launch priorities.', 'Home office', '#f59e0b');
        $event(0, '09:30', '11:30', 'Client website build sprint', 'Client', 'Deep work on homepage sections and conversion copy.', 'Home office', '#2563eb');
        $event(0, '12:00', '12:30', 'Lunch with wife', 'Family', 'Protect a calm midday break together.', 'Home', '#db2777');
        $event(0, '13:00', '15:00', 'Bean voice UX polish', 'Bean', 'Review voice capture traces and prepare public-launch demo flow.', 'Home office', '#111827');
        $event(0, '17:30', '19:00', 'Dinner + reset kitchen', 'Home', 'Start the week clean and easy.', 'Home', '#84cc16');

        // Tuesday
        $event(1, '06:30', '07:20', 'Zone 2 run + mobility', 'Health', 'Light cardio day.', 'Neighborhood', '#16a34a');
        $event(1, '08:30', '10:30', 'App feature QA pass', 'Client', 'Test client app flows and capture issue list.', 'Home office', '#2563eb');
        $event(1, '11:00', '12:00', 'Founder intro video review', 'Launch', 'Rehearse story beats and decide final framing.', 'Home office', '#dc2626');
        $event(1, '13:30', '15:30', 'HeyBean public launch checklist', 'Bean', 'Pricing, landing copy, demo account, and production smokes.', 'Home office', '#111827');
        $event(1, '18:00', '20:00', 'Date night dinner', 'Family', 'Phone-down dinner with wife.', 'Downtown', '#db2777');

        // Wednesday
        $event(2, '06:30', '07:30', 'Strength workout — legs', 'Health', 'Heavy lower-body training.', 'Gym', '#16a34a');
        $event(2, '09:00', '10:00', 'Side business finance/admin', 'Side business', 'Review invoices, receipts, and next sales action. Kept to roughly 10% of weekly time.', 'Home office', '#7c3aed');
        $event(2, '10:30', '12:00', 'Client landing-page revisions', 'Client', 'Implement requested changes and smoke mobile layout.', 'Home office', '#2563eb');
        $event(2, '13:00', '14:30', 'Bean demo script outline', 'Bean', 'Define the weekly planning screen-record flow and natural questions.', 'Home office', '#111827');
        $event(2, '15:00', '16:00', 'Grocery pickup window', 'Home', 'Pick up meal-plan ingredients.', 'Grocery store', '#84cc16');
        $event(2, '19:30', '20:30', 'Walk with wife', 'Family', 'Midweek connection and decompression.', 'Neighborhood', '#db2777');

        // Thursday
        $event(3, '06:30', '07:20', 'Pull workout + core', 'Health', 'Upper-body pull day.', 'Gym', '#16a34a');
        $event(3, '08:30', '10:00', 'Demo user dry run', 'Launch', 'Practice asking Bean to plan the week and adjust the calendar.', 'Home office', '#dc2626');
        $event(3, '10:30', '12:00', 'Client deploy window', 'Client', 'Ship approved website/app updates and verify production.', 'Home office', '#2563eb');
        $event(3, '13:30', '15:30', 'Bean product polish block', 'Bean', 'Tighten launch-day experience and public demo details.', 'Home office', '#111827');
        $event(3, '17:00', '18:00', 'Household errands', 'Home', 'Returns, dry cleaning, and quick home reset.', 'Around town', '#84cc16');

        // Friday
        $event(4, '06:30', '07:15', 'Intervals + stretch', 'Health', 'Short hard conditioning before the day starts.', 'Track', '#16a34a');
        $event(4, '09:00', '11:00', 'Record Bean weekly planning demo', 'Launch', 'Screen record the polished demo account and ask Bean to plan the week.', 'Home office', '#dc2626');
        $event(4, '11:30', '12:30', 'Side business pipeline review', 'Side business', 'Follow up with two prospects and update next actions.', 'Home office', '#7c3aed');
        $event(4, '13:30', '15:00', 'Edit demo clips and captions', 'Launch', 'Trim takes and prep social/video captions.', 'Home office', '#dc2626');
        $event(4, '18:00', '20:30', 'Friends / couple dinner', 'Family', 'Low-key social night after launch prep.', 'Local restaurant', '#db2777');

        // Saturday
        $event(5, '08:00', '09:00', 'Saturday lift + sauna', 'Health', 'Sixth workout of the week, lower intensity.', 'Gym', '#16a34a');
        $event(5, '10:00', '12:00', 'Side business creative block', 'Side business', 'Newsletter/social assets and one offer improvement.', 'Coffee shop', '#7c3aed');
        $event(5, '13:00', '15:00', 'Home project + errands', 'Home', 'Hardware store run and garage shelf cleanup.', 'Home', '#84cc16');
        $event(5, '18:30', '21:00', 'Dinner at home + movie', 'Family', 'Cook together and keep the evening open.', 'Home', '#db2777');

        // Sunday
        $event(6, '09:00', '10:00', 'Active recovery walk', 'Health', 'No formal lift; walk and mobility only.', 'Greenway', '#16a34a');
        $event(6, '10:30', '11:30', 'Church / quiet reflection', 'Personal', 'Quiet reset before next week.', null, '#64748b');
        $event(6, '13:00', '14:30', 'Meal prep + grocery reset', 'Home', 'Prep lunches, wash produce, and update grocery list.', 'Kitchen', '#84cc16');
        $event(6, '16:00', '17:00', 'Bean weekly review', 'Planning', 'Ask Bean what changed, what is overloaded, and what to move.', 'Home office', '#f59e0b');
        $event(6, '18:00', '19:30', 'Family dinner', 'Family', 'Unhurried Sunday dinner.', 'Home', '#db2777');
    }

    private function seedTasks(User $user, Workspace $workspace, string $timezone): void
    {
        $week = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $tasks = [
            ['Review founder intro script one final time', 0, 'launch', true],
            ['Confirm screen-record setup: mic, browser zoom, clean desktop', 0, 'launch', true],
            ['Draft Bean demo question list for weekly planning video', 1, 'launch', true],
            ['Export homepage pricing screenshots for launch folder', 1, 'bean', false],
            ['QA client contact form and Stripe link', 1, 'client', true],
            ['Send client preview link and notes', 2, 'client', false],
            ['Reconcile side-business receipts', 2, 'side business', false, ['recurrence' => 'interval', 'interval' => 1, 'unit' => 'weeks']],
            ['Update side-business pipeline board', 4, 'side business', false, ['recurrence' => 'interval', 'interval' => 1, 'unit' => 'weeks']],
            ['Check Bean production error logs', 2, 'bean', true, ['recurrence' => 'specific_days', 'days' => ['mon', 'wed', 'fri']]],
            ['Run HeyBean smoke tests after demo seed', 3, 'bean', true],
            ['Record Bean weekly planning demo', 4, 'launch', true],
            ['Trim demo video to best 60–90 seconds', 4, 'launch', true],
            ['Write launch caption for founder story video', 4, 'launch', false],
            ['Pay household bills', 2, 'home', true, ['recurrence' => 'interval', 'interval' => 1, 'unit' => 'months']],
            ['Order dog food and household basics', 3, 'home', false],
            ['Pick up grocery order', 2, 'home', true],
            ['Prep gym clothes and protein for the week', 6, 'health', false, ['recurrence' => 'interval', 'interval' => 1, 'unit' => 'weeks']],
            ['Plan next week with wife', 6, 'family', true, ['recurrence' => 'interval', 'interval' => 1, 'unit' => 'weeks']],
            ['Schedule car oil change', 5, 'home', false],
            ['Review analytics after launch push', 6, 'launch', true],
        ];

        foreach ($tasks as $task) {
            [$title, $dayOffset, $category, $critical] = $task;
            $metadata = $task[4] ?? [];

            Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'type' => 'todo',
                'status' => 'open',
                'notes' => $this->taskNoteFor($category),
                'category' => $category,
                'color' => $this->colorFor($category),
                'is_critical' => $critical,
                'due_at' => $week->copy()->addDays($dayOffset)->setTime(17, 0, 0)->utc(),
                'metadata' => array_merge(['demo_seed' => 'founder_week'], $metadata),
            ]);
        }
    }

    private function seedReminders(User $user, Workspace $workspace, string $timezone): void
    {
        $week = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $reminders = [
            ['Charge camera batteries for founder/demo video', 0, '20:00', 'launch', true],
            ['Text wife about Friday dinner reservation', 3, '16:30', 'family', false],
            ['Drink water after workout', 0, '08:00', 'health', false, ['recurrence' => 'specific_days', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']]],
            ['Move laundry before bed', 2, '21:00', 'home', false],
            ['Follow up with side-business prospect', 4, '11:15', 'side business', true],
            ['Start Sunday meal prep', 6, '12:45', 'home', false],
        ];

        foreach ($reminders as $reminder) {
            [$title, $dayOffset, $time, $category, $critical] = $reminder;
            $metadata = $reminder[5] ?? [];
            [$hour, $minute] = array_map('intval', explode(':', $time));
            Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $title,
                'notes' => 'Demo reminder for the weekly planning screen recording.',
                'category' => $category,
                'color' => $this->colorFor($category),
                'is_critical' => $critical,
                'remind_at' => $week->copy()->addDays($dayOffset)->setTime($hour, $minute, 0)->utc(),
                'status' => 'scheduled',
                'metadata' => array_merge(['demo_seed' => 'founder_week'], $metadata),
            ]);
        }
    }

    private function seedMealPlanNote(User $user, Workspace $workspace, string $timezone): void
    {
        $folder = NoteFolder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'name' => 'Launch Week Planning',
            'sort_order' => 1,
            'metadata' => ['demo_seed' => 'founder_week'],
        ]);

        $weekLabel = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->format('M j').'–'.Carbon::now($timezone)->endOfWeek(Carbon::SUNDAY)->format('M j');
        $markdown = <<<MARKDOWN
# Meal Plan — Launch Week ({$weekLabel})

Goal: simple dinners that keep the week healthy, married-life friendly, and low-friction while launch/video work is heavy.

## Dinners

- **Monday:** Sheet-pan chicken fajitas — https://www.budgetbytes.com/sheet-pan-chicken-fajitas/
- **Tuesday:** Date-night sushi bowls at home — https://www.loveandlemons.com/sushi-bowl/
- **Wednesday:** Turkey chili + avocado — https://www.ambitiouskitchen.com/seriously-the-best-healthy-turkey-chili/
- **Thursday:** Salmon rice bowls — https://www.wellplated.com/salmon-rice-bowl/
- **Friday:** Dinner out after recording the Bean demo.
- **Saturday:** Homemade pizza night — https://www.kingarthurbaking.com/recipes/crispy-cheesy-pan-pizza-recipe
- **Sunday:** Slow-cooker salsa verde chicken tacos — https://www.gimmesomeoven.com/slow-cooker-salsa-verde-chicken/

## Grocery list

### Produce
- Bell peppers
- Yellow onions
- Avocados
- Romaine or mixed greens
- Limes
- Cilantro
- Sweet potatoes
- Broccoli
- Bananas and berries for smoothies

### Protein
- Chicken breasts or thighs
- Lean ground turkey
- Salmon fillets
- Greek yogurt
- Eggs
- Protein powder

### Pantry / grains
- Rice
- Black beans
- Chili beans
- Crushed tomatoes
- Tortillas
- Pizza flour or premade dough
- Salsa verde
- Oats

### Dairy / cold
- Shredded cheese
- Mozzarella
- Cottage cheese
- Almond milk

### Household
- Coffee
- Paper towels
- Dishwasher pods
- Dog food

## Prep notes

- Wash/chop peppers and onions Wednesday after grocery pickup.
- Cook extra rice Wednesday for salmon bowls and taco leftovers.
- Keep Friday open because recording day may run long.
- Ask Bean Sunday: “What is overloaded next week and what should I move?”
MARKDOWN;

        Note::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'note_folder_id' => $folder->id,
            'title' => 'Meal Plan + Grocery List — Launch Week',
            'body_markdown' => $markdown,
            'plain_text' => $this->plainText($markdown),
            'is_pinned' => true,
            'sort_order' => 1,
            'metadata' => ['demo_seed' => 'founder_week', 'kind' => 'meal_plan'],
        ]);
    }

    private function taskNoteFor(string $category): string
    {
        return match ($category) {
            'launch' => 'Launch-prep task for the public HeyBean rollout and founder/demo video workflow.',
            'bean' => 'Product task for Bean/Hermes polish before public launch.',
            'client' => 'Website/app client-work task.',
            'side business' => 'Side-business task, kept intentionally small to protect the main build week.',
            'home' => 'Household task to keep the week calm and realistic.',
            'family' => 'Marriage/family rhythm task.',
            'health' => 'Workout/recovery support task.',
            default => 'Demo task for weekly planning.',
        };
    }

    private function colorFor(string $category): string
    {
        return match ($category) {
            'launch' => '#dc2626',
            'bean' => '#111827',
            'client' => '#2563eb',
            'side business' => '#7c3aed',
            'home' => '#84cc16',
            'family' => '#db2777',
            'health' => '#16a34a',
            default => '#64748b',
        };
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace('/https?:\/\/\S+/', '', $markdown) ?? $markdown;
        $text = preg_replace('/[#*_`>\-\[\]()]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
