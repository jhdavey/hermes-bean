<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\WelcomeConversationService;
use App\Services\WorkspaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class OnboardingTourScreenshotSeeder extends Seeder
{
    public const EMAIL = 'screenshots@heybean.test';

    public const PASSWORD = 'correct-horse-battery-staple';

    public const SCREENSHOT_FILES = [
        'laravel_onboarding_tour_command_center_light.png',
        'laravel_onboarding_tour_calendar_views_light.png',
        'laravel_onboarding_tour_tasks_light.png',
        'laravel_onboarding_tour_reminders_light.png',
        'laravel_onboarding_tour_notes_light.png',
    ];

    private const SOURCE = 'onboarding_tour_screenshot_seed_v1';

    private const TIMEZONE = 'America/New_York';

    public function run(): void
    {
        config([
            'services.hermes_runtime.users_home' => storage_path('app/hermes-users'),
            'services.hermes_runtime.base_home' => null,
        ]);

        DB::transaction(function (): void {
            $user = User::updateOrCreate(
                ['email' => self::EMAIL],
                $this->userAttributes([
                    'name' => 'Onboarding Screenshots',
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'onboard_complete' => true,
                    'subscription_tier' => 'pro',
                    'subscription_status' => 'trialing',
                    'subscription_trial_ends_at' => now()->addDays(7),
                    'theme' => 'green',
                    'theme_mode' => 'light',
                    'command_center_label' => 'Command Center',
                    'preferred_map_app' => 'apple_maps',
                    'notification_preferences' => [
                        'reminder_push' => true,
                        'reminder_email' => true,
                    ],
                ]),
            );

            $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
            $profile = app(AgentProfileService::class)->ensureForUser($user);
            app(AgentProfileService::class)->applyOnboarding($profile, [
                'agent_personality' => 'balanced',
                'onboarding_priorities' => ['Planning', 'Reminders', 'Focus'],
                'onboarding_context' => 'Seeded account for Laravel onboarding tour screenshots. Preferred Bean personality: Balanced. City-level location: Orlando.',
            ], 'screenshot_seed');
            app(AgentProfileService::class)->updateHomeCitySettings($profile->refresh(), 'Orlando');
            app(WelcomeConversationService::class)->ensureForUser($user);

            $this->clearSeededTourData($user, $workspaceId);
            $this->seedCategories($user, $workspaceId);
            $this->seedCalendarEvents($user, $workspaceId);
            $this->seedTasks($user, $workspaceId);
            $this->seedReminders($user, $workspaceId);
            $this->seedNotes($user, $workspaceId);
        });
    }

    private function clearSeededTourData(User $user, int $workspaceId): void
    {
        Note::withTrashed()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->forceDelete();

        NoteFolder::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->delete();

        Reminder::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->delete();

        Task::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->delete();

        CalendarEvent::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function userAttributes(array $attributes): array
    {
        return collect($attributes)
            ->filter(fn (mixed $value, string $column): bool => Schema::hasColumn('users', $column))
            ->all();
    }

    private function seedCategories(User $user, int $workspaceId): void
    {
        foreach ([
            ['name' => 'Family', 'color' => '#7BC98C'],
            ['name' => 'Money', 'color' => '#F2B94B'],
            ['name' => 'Work', 'color' => '#5E9BF2'],
            ['name' => 'Home', 'color' => '#B68BE8'],
            ['name' => 'Health', 'color' => '#F37F7F'],
        ] as $category) {
            EventCategory::updateOrCreate(
                ['user_id' => $user->id, 'name' => $category['name']],
                [
                    'workspace_id' => $workspaceId,
                    'color' => $category['color'],
                    'metadata' => ['seeded_from' => self::SOURCE],
                ],
            );
        }
    }

    private function seedCalendarEvents(User $user, int $workspaceId): void
    {
        foreach ([
            [
                'title' => 'School drop-off',
                'description' => 'Command center tour example.',
                'location' => 'Lakeview Elementary',
                'category' => 'Family',
                'color' => '#7BC98C',
                'starts_at' => '2026-07-07 07:30:00',
                'ends_at' => '2026-07-07 08:00:00',
                'is_critical' => true,
            ],
            [
                'title' => 'Team sync',
                'description' => 'Weekly launch planning sync.',
                'location' => 'Zoom',
                'category' => 'Work',
                'color' => '#5E9BF2',
                'starts_at' => '2026-07-07 08:30:00',
                'ends_at' => '2026-07-07 09:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Dentist',
                'description' => 'Calendar views tour example.',
                'location' => 'Main Street Dental',
                'category' => 'Health',
                'color' => '#F37F7F',
                'starts_at' => '2026-07-07 10:00:00',
                'ends_at' => '2026-07-07 11:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Dinner with Lauren',
                'description' => 'Reservation at 6:30.',
                'location' => 'The Grove',
                'category' => 'Family',
                'color' => '#7BC98C',
                'starts_at' => '2026-07-07 18:30:00',
                'ends_at' => '2026-07-07 20:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Beach day',
                'description' => 'Pack towels, sunscreen, and snacks.',
                'location' => 'Cocoa Beach',
                'category' => 'Family',
                'color' => '#7BC98C',
                'starts_at' => '2026-07-11 09:00:00',
                'ends_at' => '2026-07-11 17:00:00',
                'is_critical' => false,
            ],
        ] as $event) {
            CalendarEvent::create($this->resourceAttributes($user, $workspaceId, [
                ...$event,
                'starts_at' => $this->at($event['starts_at']),
                'ends_at' => $this->at($event['ends_at']),
                'status' => 'scheduled',
            ]));
        }
    }

    private function seedTasks(User $user, int $workspaceId): void
    {
        foreach ([
            [
                'title' => 'Pay insurance',
                'notes' => 'Command center tour example.',
                'category' => 'Money',
                'color' => '#F2B94B',
                'due_at' => '2026-07-07 12:15:00',
                'is_critical' => true,
            ],
            [
                'title' => 'Review launch notes',
                'notes' => 'Read the onboarding launch notes before the afternoon check-in.',
                'category' => 'Work',
                'color' => '#5E9BF2',
                'due_at' => '2026-07-07 12:15:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Order air filters',
                'notes' => 'Measure the hallway return before ordering.',
                'category' => 'Home',
                'color' => '#B68BE8',
                'due_at' => '2026-07-08 19:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Send invoice',
                'notes' => 'Quick-create example for the task screenshot.',
                'category' => 'Work',
                'color' => '#5E9BF2',
                'due_at' => '2026-07-09 09:00:00',
                'is_critical' => false,
            ],
        ] as $task) {
            Task::create($this->resourceAttributes($user, $workspaceId, [
                ...$task,
                'type' => 'todo',
                'status' => 'open',
                'due_at' => $this->at($task['due_at']),
            ]));
        }
    }

    private function seedReminders(User $user, int $workspaceId): void
    {
        foreach ([
            [
                'title' => 'Take vitamins',
                'notes' => 'Morning reminder example.',
                'category' => 'Health',
                'color' => '#F37F7F',
                'remind_at' => '2026-07-07 08:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Move laundry',
                'notes' => 'Reminder tour example.',
                'category' => 'Home',
                'color' => '#B68BE8',
                'remind_at' => '2026-07-07 18:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Dinner reminder',
                'notes' => 'Command center tour example.',
                'category' => 'Family',
                'color' => '#7BC98C',
                'remind_at' => '2026-07-07 18:00:00',
                'is_critical' => true,
            ],
            [
                'title' => 'Call Mom',
                'notes' => 'Sunday follow-up reminder.',
                'category' => 'Family',
                'color' => '#7BC98C',
                'remind_at' => '2026-07-12 17:00:00',
                'is_critical' => false,
            ],
            [
                'title' => 'Water plants',
                'notes' => 'Quick-create example for the reminder screenshot.',
                'category' => 'Home',
                'color' => '#B68BE8',
                'remind_at' => '2026-07-08 08:00:00',
                'is_critical' => false,
            ],
        ] as $reminder) {
            Reminder::create($this->resourceAttributes($user, $workspaceId, [
                ...$reminder,
                'status' => 'scheduled',
                'remind_at' => $this->at($reminder['remind_at']),
            ]));
        }
    }

    private function seedNotes(User $user, int $workspaceId): void
    {
        $folders = [];
        foreach ([
            ['name' => 'House', 'sort_order' => 1],
            ['name' => 'Travel', 'sort_order' => 2],
            ['name' => 'Ideas', 'sort_order' => 3],
        ] as $folder) {
            $folders[$folder['name']] = NoteFolder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'name' => $folder['name'],
                'sort_order' => $folder['sort_order'],
                'metadata' => ['seeded_from' => self::SOURCE],
            ]);
        }

        foreach ([
            [
                'title' => 'House projects',
                'folder' => 'House',
                'plain_text' => "Paint guest room\nFix loose cabinet pull\nCompare pantry shelves",
                'body_html' => '<ul><li>Paint guest room</li><li>Fix loose cabinet pull</li><li>Compare pantry shelves</li></ul>',
                'sort_order' => 1,
            ],
            [
                'title' => 'Ireland plan',
                'folder' => 'Travel',
                'plain_text' => "Flights\nHotels\nPacking",
                'body_html' => '<p><strong>Flights</strong></p><p>Hotels</p><ul><li>Packing</li></ul>',
                'sort_order' => 2,
            ],
            [
                'title' => 'Trip plan',
                'folder' => 'Travel',
                'plain_text' => "Friday arrival\nSaturday beach day\nSunday brunch",
                'body_html' => '<p>Friday arrival</p><p>Saturday beach day</p><p>Sunday brunch</p>',
                'sort_order' => 3,
            ],
            [
                'title' => 'Meeting notes',
                'folder' => 'Ideas',
                'plain_text' => "Launch follow-up\nOpen questions\nNext decisions",
                'body_html' => '<p>Launch follow-up</p><p>Open questions</p><p>Next decisions</p>',
                'sort_order' => 4,
            ],
        ] as $note) {
            Note::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'note_folder_id' => $folders[$note['folder']]->id,
                'title' => $note['title'],
                'body_html' => $note['body_html'],
                'plain_text' => $note['plain_text'],
                'body_delta' => null,
                'is_pinned' => $note['title'] === 'Ireland plan',
                'sort_order' => $note['sort_order'],
                'metadata' => ['seeded_from' => self::SOURCE],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resourceAttributes(User $user, int $workspaceId, array $attributes): array
    {
        return [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'metadata' => ['seeded_from' => self::SOURCE],
            ...$attributes,
        ];
    }

    private function at(string $value): Carbon
    {
        return Carbon::parse($value, self::TIMEZONE)->utc();
    }
}
