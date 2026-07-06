<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\OnboardingTourScreenshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingTourScreenshotSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_onboarding_tour_screenshot_account(): void
    {
        $this->seed(OnboardingTourScreenshotSeeder::class);

        $user = User::where('email', OnboardingTourScreenshotSeeder::EMAIL)->firstOrFail();

        $this->assertSame('Onboarding Screenshots', $user->name);
        $this->assertTrue(Hash::check(OnboardingTourScreenshotSeeder::PASSWORD, $user->password));
        $this->assertSame('light', $user->theme_mode);
        $this->assertSame('pro', $user->subscription_tier);
        $this->assertTrue($user->onboard_complete);
        $this->assertNotNull($user->default_workspace_id);

        $profile = $user->agentProfile()->firstOrFail();
        $this->assertSame('balanced', data_get($profile->settings, 'personality_type'));
        $this->assertTrue(data_get($profile->settings, 'onboarding.completed'));
        $this->assertSame('Orlando', data_get($profile->settings, 'weather.location'));

        $workspaceId = (int) $user->default_workspace_id;

        $this->assertDatabaseHas('event_categories', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'name' => 'Family',
        ]);

        $this->assertCalendarEvent($user, $workspaceId, 'School drop-off', '2026-07-07 11:30:00');
        $this->assertCalendarEvent($user, $workspaceId, 'Dentist', '2026-07-07 14:00:00');
        $this->assertCalendarEvent($user, $workspaceId, 'Beach day', '2026-07-11 13:00:00');

        $this->assertTask($user, $workspaceId, 'Pay insurance', '2026-07-07 16:15:00');
        $this->assertTask($user, $workspaceId, 'Review launch notes', '2026-07-07 16:15:00');
        $this->assertTask($user, $workspaceId, 'Order air filters', '2026-07-08 23:00:00');
        $this->assertTask($user, $workspaceId, 'Send invoice', '2026-07-09 13:00:00');

        $this->assertReminder($user, $workspaceId, 'Dinner reminder', '2026-07-07 22:00:00');
        $this->assertReminder($user, $workspaceId, 'Take vitamins', '2026-07-07 12:00:00');
        $this->assertReminder($user, $workspaceId, 'Move laundry', '2026-07-07 22:00:00');
        $this->assertReminder($user, $workspaceId, 'Call Mom', '2026-07-12 21:00:00');

        foreach (['House', 'Travel', 'Ideas'] as $folder) {
            $this->assertDatabaseHas('note_folders', [
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'name' => $folder,
            ]);
        }

        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Ireland plan',
            'is_pinned' => true,
        ]);
        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'House projects',
        ]);
    }

    public function test_it_refreshes_seeded_tour_items_when_run_again(): void
    {
        $this->seed(OnboardingTourScreenshotSeeder::class);

        $user = User::where('email', OnboardingTourScreenshotSeeder::EMAIL)->firstOrFail();
        $workspaceId = (int) $user->default_workspace_id;

        Task::where('user_id', $user->id)->where('title', 'Pay insurance')->update(['title' => 'Changed title']);
        NoteFolder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'name' => 'Old screenshot folder',
        ]);

        $this->seed(OnboardingTourScreenshotSeeder::class);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Pay insurance',
        ]);
        $this->assertDatabaseMissing('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Changed title',
        ]);
        $this->assertDatabaseMissing('note_folders', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'name' => 'Old screenshot folder',
        ]);
    }

    private function assertCalendarEvent(User $user, int $workspaceId, string $title, string $utcStartsAt): void
    {
        $event = CalendarEvent::where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('title', $title)
            ->firstOrFail();

        $this->assertTrue($event->starts_at->equalTo(Carbon::parse($utcStartsAt, 'UTC')));
    }

    private function assertTask(User $user, int $workspaceId, string $title, string $utcDueAt): void
    {
        $task = Task::where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('title', $title)
            ->firstOrFail();

        $this->assertSame('open', $task->status);
        $this->assertTrue($task->due_at->equalTo(Carbon::parse($utcDueAt, 'UTC')));
    }

    private function assertReminder(User $user, int $workspaceId, string $title, string $utcRemindAt): void
    {
        $reminder = Reminder::where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('title', $title)
            ->firstOrFail();

        $this->assertSame('scheduled', $reminder->status);
        $this->assertTrue($reminder->remind_at->equalTo(Carbon::parse($utcRemindAt, 'UTC')));
    }
}
