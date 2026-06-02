<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Notifications\ReminderDueNotification;
use App\Services\DashboardChangeNotifier;
use App\Services\FirebaseCloudMessagingService;
use Illuminate\Console\Command;

class SendDueReminderNotifications extends Command
{
    protected $signature = 'reminders:send-due-notifications {--limit=200}';

    protected $description = 'Send notifications for due HeyBean reminders.';

    public function handle(FirebaseCloudMessagingService $firebase): int
    {
        $emailSent = 0;
        $pushSent = 0;
        Reminder::query()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            })
            ->where(function ($query): void {
                $query->whereNull('metadata->email_notification_sent_at')
                    ->orWhereNull('metadata->push_notification_sent_at');
            })
            ->with('user.pushNotificationDeviceTokens')
            ->orderBy('remind_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Reminder $reminder) use ($firebase, &$emailSent, &$pushSent): void {
                $user = $reminder->user;
                if (! $user) {
                    return;
                }

                $metadata = $reminder->metadata ?? [];

                if ($user->wantsReminderEmailNotifications() && empty($metadata['email_notification_sent_at'])) {
                    $user->notify(new ReminderDueNotification($reminder));
                    $metadata['email_notification_sent_at'] = now()->toIso8601String();
                    $emailSent++;
                    $this->notifyDashboard($reminder, 'email');
                }

                if ($user->wantsReminderPushNotifications() && empty($metadata['push_notification_sent_at'])) {
                    $sentToAnyDevice = false;
                    foreach ($user->pushNotificationDeviceTokens->where('enabled', true) as $deviceToken) {
                        $sentToAnyDevice = $firebase->sendReminder($deviceToken, $reminder) || $sentToAnyDevice;
                    }
                    if ($sentToAnyDevice) {
                        $metadata['push_notification_sent_at'] = now()->toIso8601String();
                        $pushSent++;
                        $this->notifyDashboard($reminder, 'push');
                    }
                }

                if ($metadata !== ($reminder->metadata ?? [])) {
                    $reminder->forceFill(['metadata' => $metadata])->save();
                }
            });

        $this->info("Sent {$emailSent} reminder email notification(s) and {$pushSent} reminder push notification(s).");

        return self::SUCCESS;
    }

    private function notifyDashboard(Reminder $reminder, string $channel): void
    {
        app(DashboardChangeNotifier::class)->notify(
            userId: $reminder->user_id,
            workspaceId: $reminder->workspace_id,
            resourceType: 'reminder_alert',
            action: 'sent',
            resourceId: $reminder->id,
            payload: [
                'title' => $reminder->title,
                'remind_at' => $reminder->remind_at?->toIso8601String(),
                'channel' => $channel,
            ],
        );
    }
}
