<?php

namespace Tests\Feature;

use App\Models\PushNotificationDeviceToken;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_due_reminder_email_header_includes_black_bean_logo(): void
    {
        Carbon::setTestNow('2026-05-18 13:45:00');
        $user = User::factory()->create(['email' => 'logo-reminder@example.com']);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Logo email reminder',
            'notes' => 'Bring the black logo into the header.',
            'remind_at' => now(),
            'status' => 'pending',
        ]);

        $html = (string) (new ReminderDueNotification($reminder))->toMail($user)->render();

        $this->assertStringContainsString('images/bean-logo-black.png', $html);
        $this->assertStringContainsString('Reminder from Bean', $html);
        $this->assertStringContainsString('Logo email reminder', $html);
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

    public function test_authenticated_user_can_register_and_disable_push_notification_token(): void
    {
        $token = $this->apiToken('push-token@example.com');

        $this->withToken($token)->postJson('/api/push-notification-tokens', [
            'token' => 'fcm-device-token-1',
            'platform' => 'ios',
            'device_id' => 'device-1',
            'app_version' => '1.0.3',
        ])->assertCreated()
            ->assertJsonPath('data.token', 'fcm-device-token-1')
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('push_notification_device_tokens', [
            'token' => 'fcm-device-token-1',
            'token_hash' => hash('sha256', 'fcm-device-token-1'),
            'platform' => 'ios',
            'enabled' => true,
        ]);

        $this->withToken($token)->deleteJson('/api/push-notification-tokens', [
            'token' => 'fcm-device-token-1',
        ])->assertNoContent();

        $this->assertDatabaseHas('push_notification_device_tokens', [
            'token' => 'fcm-device-token-1',
            'token_hash' => hash('sha256', 'fcm-device-token-1'),
            'enabled' => false,
        ]);
    }

    public function test_due_reminder_push_is_sent_once_when_push_preference_enabled(): void
    {
        Notification::fake();
        Cache::forget('firebase.fcm.access_token');
        Carbon::setTestNow('2026-05-18 13:45:00');
        config()->set('services.firebase.project_id', 'heybean-test');
        config()->set('services.firebase.credentials_json', json_encode($this->firebaseCredentials()));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'firebase-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://fcm.googleapis.com/v1/projects/heybean-test/messages:send' => Http::response([
                'name' => 'projects/heybean-test/messages/test-message-id',
            ]),
        ]);

        $user = User::factory()->create([
            'email' => 'push-reminder@example.com',
            'notification_preferences' => [
                'reminder_push' => true,
                'reminder_email' => false,
            ],
        ]);
        PushNotificationDeviceToken::create([
            'user_id' => $user->id,
            'token' => 'fcm-device-token-2',
            'token_hash' => hash('sha256', 'fcm-device-token-2'),
            'platform' => 'android',
            'enabled' => true,
            'last_seen_at' => now(),
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Push yoga session',
            'remind_at' => now()->subMinute(),
            'status' => 'pending',
        ]);

        Artisan::call('reminders:send-due-notifications');
        Artisan::call('reminders:send-due-notifications');

        Notification::assertNothingSent();
        $this->assertSame(
            '2026-05-18T13:45:00+00:00',
            $reminder->refresh()->metadata['push_notification_sent_at']
        );
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/v1/projects/heybean-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer firebase-access-token')
            && $request['message']['token'] === 'fcm-device-token-2'
            && $request['message']['notification']['title'] === 'Reminder: Push yoga session'
            && $request['message']['data']['type'] === 'reminder_due');
    }

    private function firebaseCredentials(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);

        return [
            'type' => 'service_account',
            'project_id' => 'heybean-test',
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ];
    }
}
