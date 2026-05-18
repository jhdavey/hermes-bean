<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReminderNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_notification_preferences_are_returned_and_can_be_updated(): void
    {
        $token = $this->apiToken('notify-prefs@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.notification_preferences.reminder_push', true)
            ->assertJsonPath('data.notification_preferences.reminder_email', true);

        $this->withToken($token)->patchJson('/api/auth/me', [
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.notification_preferences.reminder_push', false)
            ->assertJsonPath('data.notification_preferences.reminder_email', true);
    }

    public function test_due_reminder_email_is_sent_once_when_email_preference_enabled(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-18 13:45:00');
        $user = User::factory()->create([
            'email' => 'reminder@example.com',
            'notification_preferences' => [
                'reminder_push' => true,
                'reminder_email' => true,
            ],
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Yoga session',
            'remind_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        Artisan::call('reminders:send-due-notifications');
        Artisan::call('reminders:send-due-notifications');

        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertSame(
            '2026-05-18T13:45:00+00:00',
            $reminder->refresh()->metadata['email_notification_sent_at']
        );
    }

    public function test_due_reminder_email_is_skipped_when_email_preference_disabled(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-18 13:45:00');
        $user = User::factory()->create([
            'email' => 'no-email@example.com',
            'notification_preferences' => [
                'reminder_push' => true,
                'reminder_email' => false,
            ],
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'title' => 'Skipped email reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        Artisan::call('reminders:send-due-notifications');

        Notification::assertNothingSent();
    }
}
