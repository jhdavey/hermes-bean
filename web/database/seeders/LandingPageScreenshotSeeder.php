<?php

namespace Database\Seeders;

use App\Models\ActivityEvent;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentProfileService;
use App\Services\WelcomeConversationService;
use App\Services\WorkspaceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class LandingPageScreenshotSeeder extends Seeder
{
    public const EMAIL = 'sarah.mitchell@example.com';

    public const PASSWORD = 'LandingScreens2026!';

    private const SOURCE = 'landing_page_screenshot_seed_v1';

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
                    'name' => 'Sarah Mitchell',
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'onboard_complete' => true,
                    'subscription_tier' => 'pro',
                    'subscription_status' => 'trialing',
                    'subscription_trial_ends_at' => now()->addDays(30),
                    'theme' => 'gold',
                    'theme_mode' => 'light',
                    'command_center_label' => 'Command Center',
                    'preferred_map_app' => 'apple_maps',
                    'notification_preferences' => [
                        'reminder_push' => true,
                        'reminder_email' => true,
                    ],
                ]),
            );

            $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
            $personalWorkspace = Workspace::findOrFail($personalWorkspaceId);
            $personalWorkspace->forceFill([
                'name' => 'Sarah Personal',
                'metadata' => ['seeded_from' => self::SOURCE, 'screenshot_use' => 'personal_life'],
            ])->save();

            $workWorkspace = $this->seedWorkWorkspace($user);
            $this->seedTeamMembers($user, $workWorkspace);
            $this->seedAgentProfiles($user, $personalWorkspace, $workWorkspace);

            $user->forceFill(['default_workspace_id' => $personalWorkspace->id])->save();

            $this->clearWorkspaceData($user, [$personalWorkspace->id, $workWorkspace->id]);
            $this->seedPersonalWorkspace($user, $personalWorkspace->id);
            $this->seedWorkWorkspaceData($user, $workWorkspace->id);
        });
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

    private function seedWorkWorkspace(User $user): Workspace
    {
        $workspace = Workspace::updateOrCreate(
            ['slug' => 'brightpath-product-team-screenshots'],
            [
                'type' => 'household',
                'name' => 'BrightPath Product Team',
                'created_by_user_id' => $user->id,
                'status' => 'active',
                'settings' => ['label' => 'Shared work workspace'],
                'metadata' => ['seeded_from' => self::SOURCE, 'screenshot_use' => 'shared_work'],
            ],
        );

        WorkspaceMembership::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            [
                'role' => 'owner',
                'status' => 'active',
                'invited_by_user_id' => null,
                'invited_email' => null,
                'accepted_at' => now(),
                'metadata' => ['seeded_from' => self::SOURCE],
            ],
        );

        return $workspace->refresh();
    }

    private function seedTeamMembers(User $owner, Workspace $workspace): void
    {
        foreach ([
            ['name' => 'Daniel Reyes', 'email' => 'daniel.reyes@example.com', 'role' => 'member'],
            ['name' => 'Priya Shah', 'email' => 'priya.shah@example.com', 'role' => 'member'],
            ['name' => 'Marcus Lee', 'email' => 'marcus.lee@example.com', 'role' => 'member'],
        ] as $member) {
            $teammate = User::updateOrCreate(
                ['email' => $member['email']],
                $this->userAttributes([
                    'name' => $member['name'],
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'onboard_complete' => true,
                    'subscription_tier' => 'premium',
                    'theme' => 'teal',
                    'theme_mode' => 'light',
                    'notification_preferences' => [
                        'reminder_push' => true,
                        'reminder_email' => true,
                    ],
                ]),
            );

            app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($teammate);

            WorkspaceMembership::updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $teammate->id],
                [
                    'role' => $member['role'],
                    'status' => 'active',
                    'invited_by_user_id' => $owner->id,
                    'invited_email' => $teammate->email,
                    'accepted_at' => now()->subWeeks(10),
                    'metadata' => ['seeded_from' => self::SOURCE],
                ],
            );
        }
    }

    private function seedAgentProfiles(User $user, Workspace $personalWorkspace, Workspace $workWorkspace): void
    {
        $profiles = app(AgentProfileService::class);
        $personal = $profiles->ensureForUser($user);
        $profiles->applyOnboarding($personal, [
            'agent_personality' => 'organizer',
            'onboarding_priorities' => ['Family schedule', 'Work planning', 'Fitness', 'Household reminders', 'Notes'],
            'onboarding_context' => 'Sarah is a 35-year-old project manager and mother of two. Emma is 6, Owen is 3, both have jiu-jitsu. Sarah works out four days per week and uses Bean to coordinate home, school, fitness, and work.',
        ], 'landing_page_seed');
        $profiles->updateHomeCitySettings($personal->refresh(), 'Raleigh');

        $work = $profiles->ensureForWorkspace($workWorkspace, $user);
        $profiles->applyOnboarding($work, [
            'agent_personality' => 'direct',
            'onboarding_priorities' => ['Project delivery', 'Stakeholder follow-up', 'Risk tracking', 'Meeting prep'],
            'onboarding_context' => 'BrightPath Product Team workspace for launch planning, sprint coordination, stakeholder updates, and cross-functional reminders.',
        ], 'landing_page_seed');
        app(WelcomeConversationService::class)->ensureForUser($user);
    }

    /**
     * @param  array<int, int|string>  $workspaceIds
     */
    private function clearWorkspaceData(User $user, array $workspaceIds): void
    {
        Note::withTrashed()
            ->where('user_id', $user->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->forceDelete();

        NoteFolder::query()
            ->where('user_id', $user->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->delete();

        foreach ([Reminder::class, Task::class, CalendarEvent::class, EventCategory::class] as $model) {
            $model::query()
                ->where('user_id', $user->id)
                ->whereIn('workspace_id', $workspaceIds)
                ->delete();
        }

        ActivityEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->where('payload->seeded_from', self::SOURCE)
            ->delete();

        ConversationSession::query()
            ->where('user_id', $user->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->where('metadata->seeded_from', self::SOURCE)
            ->delete();
    }

    private function seedPersonalWorkspace(User $user, int $workspaceId): void
    {
        $this->seedCategories($user, $workspaceId, [
            ['Family', '#7BC98C'],
            ['Kids', '#E9A6C9'],
            ['Fitness', '#66B8D8'],
            ['Home', '#B68BE8'],
            ['Money', '#F2B94B'],
            ['School', '#F37F7F'],
        ]);

        $this->seedCalendarEvents($user, $workspaceId, [
            ['Morning strength workout', 'Lower-body day at the gym before the house wakes up.', 'YMCA Downtown', 'Fitness', '#66B8D8', '2026-07-06 05:45:00', '2026-07-06 06:35:00', true],
            ['Preschool and camp drop-off', 'Owen to preschool, Emma to summer camp.', 'Oak Grove School', 'Kids', '#E9A6C9', '2026-07-06 07:20:00', '2026-07-06 08:05:00', true],
            ['Grocery pickup window', 'Weekly order: berries, lunchbox snacks, chicken, yogurt, coffee.', 'Harris Teeter', 'Home', '#B68BE8', '2026-07-06 12:10:00', '2026-07-06 12:35:00', false],
            ['Pick up kids', 'Leave work blocks with buffer for traffic.', 'Oak Grove School', 'Kids', '#E9A6C9', '2026-07-06 16:05:00', '2026-07-06 16:35:00', true],
            ['Kids jiu-jitsu class', 'Emma advanced basics, Owen tiny ninjas.', 'Triangle Jiu-Jitsu', 'Kids', '#E9A6C9', '2026-07-06 17:00:00', '2026-07-06 18:00:00', true],
            ['Dinner and homework reset', 'Tacos, reading log, pack tomorrow bags.', 'Home', 'Family', '#7BC98C', '2026-07-06 18:30:00', '2026-07-06 19:30:00', false],
            ['Emma dentist appointment', 'Bring insurance card and updated medication list.', 'Cedar Family Dental', 'Kids', '#E9A6C9', '2026-07-07 09:20:00', '2026-07-07 10:10:00', false],
            ['Tempo run', 'Four miles, easy warmup and cooldown.', 'Greenway Trail', 'Fitness', '#66B8D8', '2026-07-08 06:00:00', '2026-07-08 06:45:00', false],
            ['Parent-teacher check-in', 'Ask about Owen nap transition and Emma reading goals.', 'Oak Grove School', 'School', '#F37F7F', '2026-07-08 15:30:00', '2026-07-08 16:00:00', true],
            ['Kids jiu-jitsu class', 'Pack both gis, belts, water bottles, and sandals.', 'Triangle Jiu-Jitsu', 'Kids', '#E9A6C9', '2026-07-09 17:00:00', '2026-07-09 18:00:00', false],
            ['Friday family movie night', 'Let Emma pick movie; Owen picks snack.', 'Home', 'Family', '#7BC98C', '2026-07-10 18:45:00', '2026-07-10 20:30:00', false],
            ['Long run', 'Saturday eight-mile training run.', 'Greenway Trail', 'Fitness', '#66B8D8', '2026-07-11 06:30:00', '2026-07-11 07:45:00', false],
            ['Jiu-jitsu open mat', 'Optional practice before belt check next month.', 'Triangle Jiu-Jitsu', 'Kids', '#E9A6C9', '2026-07-11 10:00:00', '2026-07-11 11:00:00', false],
            ['Meal prep and weekly reset', 'Prep lunches, uniforms, calendar review, budget check.', 'Home', 'Home', '#B68BE8', '2026-07-12 15:00:00', '2026-07-12 17:00:00', false],
            ['Past: swim lesson', 'Completed weekend activity for screenshot history.', 'Pullen Aquatic Center', 'Kids', '#E9A6C9', '2026-06-28 10:30:00', '2026-06-28 11:15:00', false],
        ]);

        $this->seedTasks($user, $workspaceId, [
            ['Pack jiu-jitsu gis and belts', 'Put both gis, belts, rash guards, and water bottles by the door.', 'Kids', '#E9A6C9', '2026-07-06 15:45:00', true],
            ['Approve camp field trip form', 'Sign Emma form and add sunscreen note.', 'School', '#F37F7F', '2026-07-06 20:00:00', true],
            ['Pay preschool tuition', 'July autopay backup card expires this month.', 'Money', '#F2B94B', '2026-07-07 09:00:00', true],
            ['Order Owen size 4 rash guard', 'Blue or green, no zipper.', 'Kids', '#E9A6C9', '2026-07-07 18:00:00', false],
            ['Update grocery staples list', 'Add yogurt pouches, wipes, bananas, and coffee.', 'Home', '#B68BE8', '2026-07-06 11:30:00', false],
            ['Schedule oil change', 'Before beach weekend drive.', 'Home', '#B68BE8', '2026-07-08 12:00:00', false],
            ['Book babysitter for anniversary dinner', 'Ask Maya first, then check neighborhood group.', 'Family', '#7BC98C', '2026-07-09 12:00:00', false],
            ['Print reading log', 'Emma needs July reading tracker for camp.', 'School', '#F37F7F', '2026-07-10 08:00:00', false],
            ['Plan Sunday meal prep', 'Chicken bowls, breakfast muffins, kid snack boxes.', 'Home', '#B68BE8', '2026-07-12 09:00:00', false],
            ['Text Coach Lena about belt check', 'Ask whether Emma should attend Saturday open mat.', 'Kids', '#E9A6C9', '2026-07-08 17:00:00', false],
            ['Refill dishwasher tabs', 'Costco run or add to pickup.', 'Home', '#B68BE8', '2026-07-06 19:00:00', false],
            ['Send birthday RSVP', 'Mia party at trampoline park.', 'Family', '#7BC98C', '2026-07-11 10:00:00', false],
            ['Completed: compare swim lesson times', 'Picked Sunday mornings for August.', 'Kids', '#E9A6C9', '2026-06-30 13:00:00', false, 'completed'],
            ['Completed: renew library books', 'Renewed all except dinosaur book.', 'Family', '#7BC98C', '2026-07-01 10:00:00', false, 'completed'],
        ]);

        $this->seedReminders($user, $workspaceId, [
            ['Start school pickup route', 'Leave now to make jiu-jitsu without rushing.', 'Kids', '#E9A6C9', '2026-07-06 15:55:00', true],
            ['Move laundry to dryer', 'Kids uniforms and gym clothes.', 'Home', '#B68BE8', '2026-07-06 19:15:00', false],
            ['Put lunchbox ice packs in freezer', 'Both lunchboxes need fresh packs.', 'School', '#F37F7F', '2026-07-06 20:30:00', false],
            ['Take vitamins', 'After coffee, before first meeting.', 'Fitness', '#66B8D8', '2026-07-07 07:05:00', false],
            ['Bring insurance card', 'Emma dentist appointment.', 'Kids', '#E9A6C9', '2026-07-07 08:45:00', true],
            ['Text sitter about Friday', 'Confirm rate and arrival time.', 'Family', '#7BC98C', '2026-07-08 12:30:00', false],
            ['Charge tablet for car ride', 'Download kids audiobook.', 'Family', '#7BC98C', '2026-07-10 21:00:00', false],
            ['Water garden', 'Tomatoes need deep watering.', 'Home', '#B68BE8', '2026-07-07 18:30:00', false],
            ['Lay out workout clothes', 'Make Wednesday run frictionless.', 'Fitness', '#66B8D8', '2026-07-07 21:15:00', false],
        ]);

        $this->seedNotes($user, $workspaceId, [
            ['Family', 'Weekly family command center', true, "This week\n- Jiu-jitsu Monday and Thursday\n- Emma dentist Tuesday\n- Parent-teacher check-in Wednesday\n- Meal prep Sunday\n\nDefault rhythm\n- Pack backpacks after dinner\n- Shoes by the door before bed\n- Workout clothes laid out the night before", '<h2>This week</h2><ul><li>Jiu-jitsu Monday and Thursday</li><li>Emma dentist Tuesday</li><li>Parent-teacher check-in Wednesday</li><li>Meal prep Sunday</li></ul><h2>Default rhythm</h2><ul><li>Pack backpacks after dinner</li><li>Shoes by the door before bed</li><li>Workout clothes laid out the night before</li></ul>'],
            ['Family', 'Kids jiu-jitsu checklist', true, "Gear\n- Emma gi, white belt, mouthguard\n- Owen gi, snack, water\n- Backup hair ties\n\nCoach notes\n- Emma is working on shrimping and breakfalls\n- Owen does better if he eats before class", '<h2>Gear</h2><ul><li>Emma gi, white belt, mouthguard</li><li>Owen gi, snack, water</li><li>Backup hair ties</li></ul><h2>Coach notes</h2><ul><li>Emma is working on shrimping and breakfalls</li><li>Owen does better if he eats before class</li></ul>'],
            ['Home', 'Meal plan: July 6 week', false, "Monday tacos\nTuesday sheet-pan salmon\nWednesday leftovers\nThursday pasta after jiu-jitsu\nFriday movie night pizza\nSunday prep: muffins, chicken bowls, chopped fruit", '<ul><li>Monday tacos</li><li>Tuesday sheet-pan salmon</li><li>Wednesday leftovers</li><li>Thursday pasta after jiu-jitsu</li><li>Friday movie night pizza</li><li>Sunday prep: muffins, chicken bowls, chopped fruit</li></ul>'],
            ['Home', 'Home maintenance queue', false, "July\n- Replace HVAC filters\n- Schedule oil change\n- Fix loose drawer pull\n- Refill emergency snack bin\n\nAugust\n- School shoe inventory\n- Label lunch containers", '<h2>July</h2><ul><li>Replace HVAC filters</li><li>Schedule oil change</li><li>Fix loose drawer pull</li><li>Refill emergency snack bin</li></ul><h2>August</h2><ul><li>School shoe inventory</li><li>Label lunch containers</li></ul>'],
            ['Fitness', 'Workout plan', false, "Four-day target\n- Monday strength\n- Wednesday tempo run\n- Friday upper body\n- Saturday long run\n\nKeep workouts under 50 minutes on weekdays.", '<p><strong>Four-day target</strong></p><ul><li>Monday strength</li><li>Wednesday tempo run</li><li>Friday upper body</li><li>Saturday long run</li></ul><p>Keep workouts under 50 minutes on weekdays.</p>'],
            ['School', 'School and camp notes', false, "Emma: reading log due Fridays, bring swimsuit Wednesdays.\nOwen: nap blanket comes home Thursdays, extra pull-ups in cubby.", '<p><strong>Emma</strong>: reading log due Fridays, bring swimsuit Wednesdays.</p><p><strong>Owen</strong>: nap blanket comes home Thursdays, extra pull-ups in cubby.</p>'],
            ['Ideas', 'Birthday and weekend ideas', false, "Birthday ideas\n- Backyard obstacle course\n- Jiu-jitsu ninja theme\n- Cupcakes from Yellow Dog\n\nWeekend\n- Library story time\n- Farmers market\n- Bike path if weather is under 90", '<h2>Birthday ideas</h2><ul><li>Backyard obstacle course</li><li>Jiu-jitsu ninja theme</li><li>Cupcakes from Yellow Dog</li></ul><h2>Weekend</h2><ul><li>Library story time</li><li>Farmers market</li><li>Bike path if weather is under 90</li></ul>'],
        ]);

        $this->seedConversation($user, $workspaceId, 'Family weekly planning', [
            ['user', 'Can you help me make Monday manageable with the kids and jiu-jitsu?'],
            ['assistant', 'I added the pickup buffer, jiu-jitsu reminder, and evening reset tasks. Your highest-risk handoff is the 3:55 pickup route reminder.'],
            ['user', 'Also remember both kids need their gis packed before school pickup.'],
            ['assistant', 'Done. I added a critical task to pack both jiu-jitsu gis, belts, rash guards, and water bottles.'],
        ]);
    }

    private function seedWorkWorkspaceData(User $user, int $workspaceId): void
    {
        $this->seedCategories($user, $workspaceId, [
            ['Meetings', '#5E9BF2'],
            ['Launch', '#F2B94B'],
            ['Risk', '#F37F7F'],
            ['Design', '#B68BE8'],
            ['Team', '#7BC98C'],
        ]);

        $this->seedCalendarEvents($user, $workspaceId, [
            ['Daily delivery standup', 'Sprint 18 launch blockers and owner check.', 'Zoom', 'Meetings', '#5E9BF2', '2026-07-06 09:00:00', '2026-07-06 09:20:00', true],
            ['Roadmap tradeoff review', 'Scope decisions for mobile onboarding and billing cleanup.', 'Conference Room 3', 'Launch', '#F2B94B', '2026-07-06 10:30:00', '2026-07-06 11:30:00', true],
            ['Analytics QA sync', 'Validate dashboard instrumentation and funnel events.', 'Zoom', 'Launch', '#F2B94B', '2026-07-06 13:00:00', '2026-07-06 13:45:00', false],
            ['Design handoff: settings polish', 'Review responsive states and screenshot-ready flows.', 'Figma', 'Design', '#B68BE8', '2026-07-06 14:30:00', '2026-07-06 15:15:00', false],
            ['Client beta review', 'Walk through command center, notes, and shared workspace flows.', 'Zoom', 'Launch', '#F2B94B', '2026-07-07 11:00:00', '2026-07-07 12:00:00', true],
            ['Risk triage', 'Dependency risks, launch comms, QA coverage.', 'Zoom', 'Risk', '#F37F7F', '2026-07-08 09:30:00', '2026-07-08 10:00:00', true],
            ['Stakeholder readout', 'Weekly status and decisions needed.', 'Boardroom', 'Meetings', '#5E9BF2', '2026-07-09 15:00:00', '2026-07-09 15:45:00', false],
            ['Sprint retro prep', 'Collect themes before Friday retro.', 'Zoom', 'Team', '#7BC98C', '2026-07-10 10:00:00', '2026-07-10 10:45:00', false],
            ['Past: launch planning workshop', 'Scope map and team alignment.', 'Conference Room 2', 'Launch', '#F2B94B', '2026-06-24 13:00:00', '2026-06-24 15:00:00', false],
        ]);

        $this->seedTasks($user, $workspaceId, [
            ['Finalize sprint 18 launch plan', 'Lock milestones, owners, QA gates, and comms timeline.', 'Launch', '#F2B94B', '2026-07-06 16:00:00', true],
            ['Send beta launch status update', 'Include risks, decisions needed, and Friday demo scope.', 'Launch', '#F2B94B', '2026-07-06 17:00:00', true],
            ['Update risk register', 'Add analytics delay mitigation and mobile QA owner.', 'Risk', '#F37F7F', '2026-07-07 12:00:00', true],
            ['Review analytics dashboard QA', 'Confirm event names match Product and Data contract.', 'Launch', '#F2B94B', '2026-07-07 15:00:00', false],
            ['Draft stakeholder readout', 'One-page summary with launch health, open decisions, next milestones.', 'Meetings', '#5E9BF2', '2026-07-08 16:00:00', false],
            ['Confirm design signoff on settings screen', 'Priya to validate desktop and mobile screenshots.', 'Design', '#B68BE8', '2026-07-06 14:00:00', false],
            ['Follow up with Marcus on API retry edge case', 'Need answer before client beta review.', 'Risk', '#F37F7F', '2026-07-07 10:00:00', false],
            ['Prepare Friday retro board', 'Seed wins, friction, and launch readiness prompts.', 'Team', '#7BC98C', '2026-07-10 09:00:00', false],
            ['Schedule post-beta decision meeting', 'Add Daniel, Priya, Marcus, and client sponsor.', 'Meetings', '#5E9BF2', '2026-07-09 11:30:00', false],
            ['Completed: publish sprint 17 notes', 'Archived in team notes.', 'Team', '#7BC98C', '2026-06-30 15:00:00', false, 'completed'],
        ]);

        $this->seedReminders($user, $workspaceId, [
            ['Nudge Priya on mobile empty-state screenshots', 'Need final Figma frame before handoff.', 'Design', '#B68BE8', '2026-07-06 13:50:00', false],
            ['Send roadmap review agenda', 'Attach decision list before 10:30 meeting.', 'Meetings', '#5E9BF2', '2026-07-06 10:00:00', true],
            ['Check Daniel analytics QA status', 'Ask whether funnel events are ready for beta.', 'Launch', '#F2B94B', '2026-07-07 14:00:00', false],
            ['Risk triage pre-read', 'Read Marcus notes before Wednesday risk triage.', 'Risk', '#F37F7F', '2026-07-08 08:45:00', true],
            ['Send Friday rollout note', 'Include owners and what changed since beta.', 'Launch', '#F2B94B', '2026-07-10 13:00:00', false],
            ['Collect retro themes', 'Ask team for one win and one friction point.', 'Team', '#7BC98C', '2026-07-09 16:30:00', false],
        ]);

        $this->seedNotes($user, $workspaceId, [
            ['Launch', 'Q3 launch plan', true, "Launch goals\n- Beta walkthrough Tuesday\n- Analytics QA complete by Wednesday\n- Stakeholder readout Thursday\n- Friday retro and rollout note\n\nDecision log\n- Keep onboarding tour highlight scope tight\n- Move nice-to-have reporting to post-beta", '<h2>Launch goals</h2><ul><li>Beta walkthrough Tuesday</li><li>Analytics QA complete by Wednesday</li><li>Stakeholder readout Thursday</li><li>Friday retro and rollout note</li></ul><h2>Decision log</h2><ul><li>Keep onboarding tour highlight scope tight</li><li>Move nice-to-have reporting to post-beta</li></ul>'],
            ['Risk', 'Risk register', true, "Open risks\n- Analytics event mismatch\n- Mobile QA coverage\n- Client sponsor availability\n\nMitigation\n- Daniel validates events\n- Priya signs off on screenshots\n- Sarah sends concise decision memo", '<h2>Open risks</h2><ul><li>Analytics event mismatch</li><li>Mobile QA coverage</li><li>Client sponsor availability</li></ul><h2>Mitigation</h2><ul><li>Daniel validates events</li><li>Priya signs off on screenshots</li><li>Sarah sends concise decision memo</li></ul>'],
            ['Meetings', 'Stakeholder update draft', false, "Summary: launch remains on track with two monitored risks.\nNeeds: confirm client beta attendee list and approve Friday rollout note.\nNext milestone: beta review Tuesday 11 AM.", '<p><strong>Summary:</strong> launch remains on track with two monitored risks.</p><p><strong>Needs:</strong> confirm client beta attendee list and approve Friday rollout note.</p><p><strong>Next milestone:</strong> beta review Tuesday 11 AM.</p>'],
            ['Team', 'Team operating rhythm', false, "Daily standup 9:00\nRisk triage Wednesday\nStakeholder readout Thursday\nRetro Friday\n\nSarah owns plan and comms, Daniel owns analytics, Priya owns design signoff, Marcus owns API readiness.", '<ul><li>Daily standup 9:00</li><li>Risk triage Wednesday</li><li>Stakeholder readout Thursday</li><li>Retro Friday</li></ul><p>Sarah owns plan and comms, Daniel owns analytics, Priya owns design signoff, Marcus owns API readiness.</p>'],
            ['Design', 'Settings screenshot checklist', false, "Desktop\n- Workspaces without dividers\n- Email edit in header\n- Gold theme plus button\n\nMobile\n- Delete account as red text\n- Workspace selector spacing", '<h2>Desktop</h2><ul><li>Workspaces without dividers</li><li>Email edit in header</li><li>Gold theme plus button</li></ul><h2>Mobile</h2><ul><li>Delete account as red text</li><li>Workspace selector spacing</li></ul>'],
        ]);

        $this->seedConversation($user, $workspaceId, 'Launch week coordination', [
            ['user', 'What are the launch risks I need to push today?'],
            ['assistant', 'Top risks today: analytics QA owner confirmation, settings screenshot signoff, and API retry answer before the beta review. I kept each as a task or reminder in this workspace.'],
            ['user', 'Add a reminder to nudge Priya before design handoff.'],
            ['assistant', 'Done. I added the Priya reminder for 1:50 PM before the 2:30 design handoff.'],
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $categories
     */
    private function seedCategories(User $user, int $workspaceId, array $categories): void
    {
        foreach ($categories as [$name, $color]) {
            EventCategory::create($this->columnAttributes('event_categories', [
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'name' => $name,
                'color' => $color,
                'metadata' => ['seeded_from' => self::SOURCE],
            ]));
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: bool}>  $events
     */
    private function seedCalendarEvents(User $user, int $workspaceId, array $events): void
    {
        foreach ($events as [$title, $description, $location, $category, $color, $startsAt, $endsAt, $critical]) {
            CalendarEvent::create($this->resourceAttributes($user, $workspaceId, [
                'title' => $title,
                'description' => $description,
                'location' => $location,
                'category' => $category,
                'color' => $color,
                'is_critical' => $critical,
                'starts_at' => $this->at($startsAt),
                'ends_at' => $this->at($endsAt),
                'status' => 'scheduled',
            ]));
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool, 6?: string}>  $tasks
     */
    private function seedTasks(User $user, int $workspaceId, array $tasks): void
    {
        foreach ($tasks as $task) {
            [$title, $notes, $category, $color, $dueAt, $critical] = $task;
            $status = $task[6] ?? 'open';
            Task::create($this->resourceAttributes($user, $workspaceId, [
                'title' => $title,
                'type' => 'todo',
                'status' => $status,
                'notes' => $notes,
                'category' => $category,
                'color' => $color,
                'is_critical' => $critical,
                'due_at' => $this->at($dueAt),
                'completed_at' => $status === 'completed' ? $this->at($dueAt)->addHour() : null,
            ]));
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>  $reminders
     */
    private function seedReminders(User $user, int $workspaceId, array $reminders): void
    {
        foreach ($reminders as [$title, $notes, $category, $color, $remindAt, $critical]) {
            Reminder::create($this->resourceAttributes($user, $workspaceId, [
                'title' => $title,
                'notes' => $notes,
                'category' => $category,
                'color' => $color,
                'is_critical' => $critical,
                'remind_at' => $this->at($remindAt),
                'status' => 'scheduled',
            ]));
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: bool, 3: string, 4: string}>  $notes
     */
    private function seedNotes(User $user, int $workspaceId, array $notes): void
    {
        $folders = [];
        foreach (array_values(array_unique(array_column($notes, 0))) as $index => $folderName) {
            $folders[$folderName] = NoteFolder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'name' => $folderName,
                'sort_order' => $index + 1,
                'metadata' => ['seeded_from' => self::SOURCE],
            ]);
        }

        foreach ($notes as $index => [$folderName, $title, $pinned, $plainText, $bodyHtml]) {
            Note::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'note_folder_id' => $folders[$folderName]->id,
                'title' => $title,
                'body_html' => $bodyHtml,
                'plain_text' => $plainText,
                'body_delta' => null,
                'is_pinned' => $pinned,
                'sort_order' => $index + 1,
                'metadata' => ['seeded_from' => self::SOURCE],
            ]);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $messages
     */
    private function seedConversation(User $user, int $workspaceId, string $title, array $messages): void
    {
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => $title,
            'status' => 'active',
            'runtime_mode' => 'tools',
            'metadata' => ['seeded_from' => self::SOURCE],
            'last_activity_at' => $this->at('2026-07-06 15:30:00'),
        ]);

        foreach ($messages as [$role, $content]) {
            $session->messages()->create([
                'user_id' => $user->id,
                'role' => $role,
                'content' => $content,
                'metadata' => ['seeded_from' => self::SOURCE],
            ]);
        }

        ActivityEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.summary.generated',
            'tool_name' => 'seeded_dashboard',
            'status' => 'succeeded',
            'payload' => ['seeded_from' => self::SOURCE, 'title' => $title],
        ]);
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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function columnAttributes(string $table, array $attributes): array
    {
        return collect($attributes)
            ->filter(fn (mixed $value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }

    private function at(string $value): Carbon
    {
        return Carbon::parse($value, self::TIMEZONE)->utc();
    }
}
