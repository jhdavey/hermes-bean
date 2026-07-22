<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoFounderWeekSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_demo_seed_requires_force_and_an_explicit_password(): void
    {
        $this->app->instance('env', 'production');

        $this->assertSame(1, Artisan::call('demo:seed-founder-week'));
        $this->assertSame(1, Artisan::call('demo:seed-founder-week', ['--force' => true]));
        $this->assertDatabaseMissing('users', ['email' => 'harleydemo@email.com']);
    }

    public function test_founder_week_demo_seed_creates_login_account_and_planning_data_idempotently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21T16:00:00Z'));

        $firstExit = Artisan::call('demo:seed-founder-week');
        $secondExit = Artisan::call('demo:seed-founder-week');

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);

        $user = User::where('email', 'harleydemo@email.com')->firstOrFail();
        $this->assertSame('Harley', $user->name);
        $this->assertTrue(Hash::check('password1234', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue((bool) $user->onboard_complete);
        $this->assertSame('pro', $user->subscription_tier);
        $this->assertSame('America/New_York', $user->timezone);
        $this->assertNotNull($user->default_workspace_id);

        $this->assertSame(36, CalendarEvent::where('user_id', $user->id)->count());
        $this->assertSame(20, Task::where('user_id', $user->id)->count());
        $this->assertSame(6, Reminder::where('user_id', $user->id)->count());
        $this->assertSame(1, Note::where('user_id', $user->id)->count());

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'title' => 'Weekly planning with Bean',
            'category' => 'Planning',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'title' => 'Record Bean weekly planning demo',
            'category' => 'Launch',
        ]);
        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Plan next week with wife',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Follow up with side-business prospect',
            'status' => 'scheduled',
        ]);

        $mealPlan = Note::where('user_id', $user->id)->where('title', 'Meal Plan + Grocery List — Launch Week')->firstOrFail();
        $this->assertStringContainsString('## Dinners', $mealPlan->body_markdown);
        $this->assertStringContainsString('https://www.budgetbytes.com/sheet-pan-chicken-fajitas/', $mealPlan->body_markdown);
        $this->assertStringContainsString('## Grocery list', $mealPlan->body_markdown);
    }
}
