<?php

namespace Tests\Feature;

use App\Models\PushNotificationDeviceToken;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Notifications\ReminderDueNotification;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
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
        $token = $this->premiumApiToken('notify-prefs@example.com');

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
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => true,
                'reminder_email' => true,
            ],
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Yoga session',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        Artisan::call('reminders:send-due-notifications');
        Artisan::call('reminders:send-due-notifications');

        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertSame(
            '2026-05-18T13:45:00+00:00',
            $reminder->refresh()->metadata['email_notification_sent_at']
        );
    }

    public function test_only_exact_scheduled_reminders_are_eligible_for_due_notifications(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-18 13:45:00');
        $user = User::factory()->create([
            'email' => 'canonical-reminder-notification@example.com',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        $scheduled = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Canonical scheduled reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);
        $legacy = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Legacy pending reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'pending',
        ]);
        $completed = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Completed reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'completed',
        ]);

        Artisan::call('reminders:send-due-notifications');

        Notification::assertSentTo(
            $user,
            ReminderDueNotification::class,
            fn (ReminderDueNotification $notification): bool => $notification->toArray($user)['reminder_id'] === $scheduled->id,
        );
        Notification::assertSentToTimes($user, ReminderDueNotification::class, 1);
        $this->assertArrayNotHasKey('email_notification_sent_at', $legacy->refresh()->metadata ?? []);
        $this->assertArrayNotHasKey('email_notification_sent_at', $completed->refresh()->metadata ?? []);
    }

    public function test_due_reminder_email_failure_does_not_fail_command_or_retry_immediately(): void
    {
        Carbon::setTestNow('2026-05-18 13:45:00');
        $sendAttempts = 0;
        $this->app->bind(NotificationDispatcher::class, function () use (&$sendAttempts) {
            return new class($sendAttempts) implements NotificationDispatcher
            {
                public function __construct(private int &$sendAttempts) {}

                public function send($notifiables, $notification): void
                {
                    $this->sendAttempts++;

                    throw new \RuntimeException('You have reached your daily email sending quota.');
                }

                public function sendNow($notifiables, $notification, ?array $channels = null): void
                {
                    $this->send($notifiables, $notification);
                }
            };
        });

        $user = User::factory()->create([
            'email' => 'quota-reminder@example.com',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Quota-safe reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));
        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));

        $metadata = $reminder->refresh()->metadata;
        $delivery = $metadata['notification_delivery'];
        $this->assertSame(1, $sendAttempts);
        $this->assertSame('2026-05-18T13:45:00+00:00', $metadata['email_notification_failed_at']);
        $this->assertSame('2026-05-19T00:00:00+00:00', $delivery['email_retry_after_by_user'][(string) $user->id]);
        $this->assertArrayNotHasKey((string) $user->id, $delivery['email_sent_at_by_user']);
    }

    public function test_reserved_test_domain_recipient_is_suppressed_without_contacting_mailer(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-07-20 15:01:08');
        config()->set('mail.suppress_reserved_recipient_domains', true);
        $user = User::factory()->create([
            'email' => 'production-fixture@example.com',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Reserved-domain reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
            'metadata' => [
                'notification_delivery' => [
                    'email_failed_at_by_user' => [
                        (string) $user->id => '2026-07-20T15:00:00+00:00',
                    ],
                    'email_retry_after_by_user' => [
                        (string) $user->id => '2026-07-20T16:00:00+00:00',
                    ],
                ],
            ],
        ]);

        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));
        $this->assertStringContainsString('suppressed 1 undeliverable', Artisan::output());
        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));

        Notification::assertNothingSent();
        $metadata = $reminder->refresh()->metadata;
        $delivery = $metadata['notification_delivery'];
        $recipientId = (string) $user->id;
        $this->assertSame('2026-07-20T15:01:08+00:00', $delivery['email_terminal_at_by_user'][$recipientId]);
        $this->assertSame('reserved_domain', $delivery['email_terminal_reason_by_user'][$recipientId]);
        $this->assertArrayNotHasKey($recipientId, $delivery['email_retry_after_by_user']);
        $this->assertSame('2026-07-20T15:01:08+00:00', $metadata['email_notification_resolved_at']);
        $this->assertSame('2026-07-20T15:01:08+00:00', $metadata['push_notification_resolved_at']);
    }

    public function test_resend_invalid_to_failure_is_permanent_and_is_not_retried(): void
    {
        Carbon::setTestNow('2026-07-20 15:01:08');
        $sendAttempts = 0;
        $this->app->bind(NotificationDispatcher::class, function () use (&$sendAttempts) {
            return new class($sendAttempts) implements NotificationDispatcher
            {
                public function __construct(private int &$sendAttempts) {}

                public function send($notifiables, $notification): void
                {
                    $this->sendAttempts++;

                    throw new \RuntimeException('Request to Resend API failed. Reason: Invalid `to` field. Please use our testing email address instead of domains like `example.com`.');
                }

                public function sendNow($notifiables, $notification, ?array $channels = null): void
                {
                    $this->send($notifiables, $notification);
                }
            };
        });

        $user = User::factory()->create([
            'email' => 'blocked-recipient@heybean.dev',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'title' => 'Permanently rejected reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, Artisan::call('reminders:send-due-notifications'));

        $metadata = $reminder->refresh()->metadata;
        $delivery = $metadata['notification_delivery'];
        $recipientId = (string) $user->id;
        $this->assertSame(1, $sendAttempts);
        $this->assertSame('invalid_recipient', $delivery['email_terminal_reason_by_user'][$recipientId]);
        $this->assertArrayNotHasKey($recipientId, $delivery['email_retry_after_by_user']);
        $this->assertSame('2026-07-20T15:01:08+00:00', $metadata['email_notification_resolved_at']);
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
            'status' => 'scheduled',
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
            'status' => 'scheduled',
        ]);

        Artisan::call('reminders:send-due-notifications');

        Notification::assertNothingSent();
    }

    public function test_due_shared_workspace_reminder_email_is_sent_to_selected_recipients(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-18 13:45:00');
        $owner = User::factory()->create([
            'email' => 'shared-reminder-owner@example.com',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        $member = User::factory()->create([
            'email' => 'shared-reminder-member@example.com',
            'subscription_tier' => 'premium',
            'notification_preferences' => [
                'reminder_push' => false,
                'reminder_email' => true,
            ],
        ]);
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($owner);
        $workspace = app(WorkspaceService::class)->createHousehold($owner, 'Shared House');
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        $reminder = Reminder::create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'title' => 'Shared dinner reminder',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
            'metadata' => [
                'notification_recipients_by_workspace' => [
                    (string) $workspace->id => [$owner->id, $member->id],
                ],
            ],
        ]);

        Artisan::call('reminders:send-due-notifications');
        Artisan::call('reminders:send-due-notifications');

        Notification::assertSentToTimes($owner, ReminderDueNotification::class, 1);
        Notification::assertSentToTimes($member, ReminderDueNotification::class, 1);
        $delivery = $reminder->refresh()->metadata['notification_delivery']['email_sent_at_by_user'];
        $this->assertSame('2026-05-18T13:45:00+00:00', $delivery[(string) $owner->id]);
        $this->assertSame('2026-05-18T13:45:00+00:00', $delivery[(string) $member->id]);
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
            'status' => 'scheduled',
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
