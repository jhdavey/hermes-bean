<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Services\DashboardChangeNotifier;
use App\Services\FirebaseCloudMessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendDueReminderNotifications extends Command
{
    protected $signature = 'reminders:send-due-notifications {--limit=200}';

    protected $description = 'Send notifications for due HeyBean reminders.';

    public function handle(FirebaseCloudMessagingService $firebase): int
    {
        $emailSent = 0;
        $emailFailed = 0;
        $emailSuppressed = 0;
        $pushSent = 0;
        Reminder::query()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->where('status', 'scheduled')
            ->where(function ($query): void {
                $query->where(function ($email): void {
                    $email->whereNull('metadata->email_notification_sent_at')
                        ->whereNull('metadata->email_notification_resolved_at');
                })->orWhere(function ($push): void {
                    $push->whereNull('metadata->push_notification_sent_at')
                        ->whereNull('metadata->push_notification_resolved_at');
                });
            })
            ->with('user')
            ->orderBy('remind_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Reminder $reminder) use ($firebase, &$emailSent, &$emailFailed, &$emailSuppressed, &$pushSent): void {
                $recipients = $this->notificationRecipients($reminder);
                if ($recipients->isEmpty()) {
                    $this->resolveNotificationChannels($reminder);

                    return;
                }

                $metadata = $reminder->metadata ?? [];
                $delivery = is_array($metadata['notification_delivery'] ?? null) ? $metadata['notification_delivery'] : [];
                $emailSentByUser = is_array($delivery['email_sent_at_by_user'] ?? null) ? $delivery['email_sent_at_by_user'] : [];
                $emailFailedByUser = is_array($delivery['email_failed_at_by_user'] ?? null) ? $delivery['email_failed_at_by_user'] : [];
                $emailRetryAfterByUser = is_array($delivery['email_retry_after_by_user'] ?? null) ? $delivery['email_retry_after_by_user'] : [];
                $emailTerminalAtByUser = is_array($delivery['email_terminal_at_by_user'] ?? null) ? $delivery['email_terminal_at_by_user'] : [];
                $emailTerminalReasonByUser = is_array($delivery['email_terminal_reason_by_user'] ?? null) ? $delivery['email_terminal_reason_by_user'] : [];
                $pushSentByUser = is_array($delivery['push_sent_at_by_user'] ?? null) ? $delivery['push_sent_at_by_user'] : [];

                foreach ($recipients as $recipient) {
                    $recipientId = (string) $recipient->id;
                    if (
                        $recipient->wantsReminderEmailNotifications()
                        && empty($emailSentByUser[$recipientId])
                        && empty($emailTerminalAtByUser[$recipientId])
                    ) {
                        $suppressionReason = $this->emailSuppressionReason($recipient);
                        if ($suppressionReason !== null) {
                            $emailTerminalAtByUser[$recipientId] = now()->toIso8601String();
                            $emailTerminalReasonByUser[$recipientId] = $suppressionReason;
                            unset($emailRetryAfterByUser[$recipientId]);
                            $emailSuppressed++;
                        } elseif (! $this->emailRetryIsActive($emailRetryAfterByUser[$recipientId] ?? null)) {
                            try {
                                $recipient->notify(new ReminderDueNotification($reminder));
                                $emailSentByUser[$recipientId] = now()->toIso8601String();
                                unset(
                                    $emailFailedByUser[$recipientId],
                                    $emailRetryAfterByUser[$recipientId],
                                    $emailTerminalAtByUser[$recipientId],
                                    $emailTerminalReasonByUser[$recipientId],
                                );
                                $metadata['email_notification_sent_at'] ??= $emailSentByUser[$recipientId];
                                $emailSent++;
                                $this->notifyDashboard($reminder, 'email', $recipient);
                            } catch (\Throwable $exception) {
                                $failedAt = now()->toIso8601String();
                                $emailFailedByUser[$recipientId] = $failedAt;
                                $metadata['email_notification_failed_at'] ??= $failedAt;
                                $emailFailed++;

                                if ($this->isPermanentEmailDeliveryFailure($exception)) {
                                    $emailTerminalAtByUser[$recipientId] = $failedAt;
                                    $emailTerminalReasonByUser[$recipientId] = 'invalid_recipient';
                                    unset($emailRetryAfterByUser[$recipientId]);
                                    Log::warning('Reminder email notification failed permanently; delivery will not be retried.', [
                                        'reminder_id' => $reminder->id,
                                        'recipient_id' => $recipient->id,
                                        'error' => $exception->getMessage(),
                                    ]);
                                } else {
                                    $emailRetryAfterByUser[$recipientId] = $this->emailRetryAfter($exception)->toIso8601String();
                                    Log::warning('Reminder email notification failed; delivery will be retried later.', [
                                        'reminder_id' => $reminder->id,
                                        'recipient_id' => $recipient->id,
                                        'error' => $exception->getMessage(),
                                        'retry_after' => $emailRetryAfterByUser[$recipientId],
                                    ]);
                                }
                            }
                        }
                    }

                    if ($recipient->wantsReminderPushNotifications() && empty($pushSentByUser[$recipientId])) {
                        $sentToAnyDevice = false;
                        foreach ($recipient->pushNotificationDeviceTokens->where('enabled', true) as $deviceToken) {
                            $sentToAnyDevice = $firebase->sendReminder($deviceToken, $reminder) || $sentToAnyDevice;
                        }
                        if ($sentToAnyDevice) {
                            $pushSentByUser[$recipientId] = now()->toIso8601String();
                            $metadata['push_notification_sent_at'] ??= $pushSentByUser[$recipientId];
                            $pushSent++;
                            $this->notifyDashboard($reminder, 'push', $recipient);
                        }
                    }
                }

                $delivery['email_sent_at_by_user'] = $emailSentByUser;
                $delivery['email_failed_at_by_user'] = $emailFailedByUser;
                $delivery['email_retry_after_by_user'] = $emailRetryAfterByUser;
                $delivery['email_terminal_at_by_user'] = $emailTerminalAtByUser;
                $delivery['email_terminal_reason_by_user'] = $emailTerminalReasonByUser;
                $delivery['push_sent_at_by_user'] = $pushSentByUser;
                $metadata['notification_delivery'] = $delivery;

                if ($recipients->every(fn (User $recipient): bool => $this->emailDeliveryIsResolved(
                    $recipient,
                    $emailSentByUser,
                    $emailTerminalAtByUser,
                ))) {
                    $metadata['email_notification_resolved_at'] ??= now()->toIso8601String();
                }

                if ($recipients->every(fn (User $recipient): bool => $this->pushDeliveryIsResolved(
                    $recipient,
                    $pushSentByUser,
                ))) {
                    $metadata['push_notification_resolved_at'] ??= now()->toIso8601String();
                }

                if ($metadata !== ($reminder->metadata ?? [])) {
                    $reminder->forceFill(['metadata' => $metadata])->save();
                }
            });

        $this->info("Sent {$emailSent} reminder email notification(s), suppressed {$emailSuppressed} undeliverable reminder email notification(s), failed {$emailFailed} reminder email notification(s), and sent {$pushSent} reminder push notification(s).");

        return self::SUCCESS;
    }

    private function emailRetryIsActive(mixed $retryAfter): bool
    {
        if (! is_string($retryAfter) || trim($retryAfter) === '') {
            return false;
        }

        try {
            return now()->lt(Carbon::parse($retryAfter));
        } catch (\Throwable) {
            return false;
        }
    }

    private function emailRetryAfter(\Throwable $exception): Carbon
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'daily email sending quota') || str_contains($message, 'daily quota')
            ? now()->addDay()->startOfDay()
            : now()->addHour();
    }

    private function emailSuppressionReason(User $recipient): ?string
    {
        if (! config('mail.suppress_reserved_recipient_domains', false)) {
            return null;
        }

        $email = Str::lower(trim((string) $recipient->email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_recipient';
        }

        $domain = Str::afterLast($email, '@');
        foreach ((array) config('mail.reserved_recipient_domains', []) as $reservedDomain) {
            $reservedDomain = Str::lower(trim((string) $reservedDomain));
            if (
                $reservedDomain !== ''
                && ($domain === $reservedDomain || str_ends_with($domain, '.'.$reservedDomain))
            ) {
                return 'reserved_domain';
            }
        }

        return null;
    }

    private function isPermanentEmailDeliveryFailure(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'invalid `to` field')
            || str_contains($message, 'invalid "to" field')
            || str_contains($message, 'testing email address instead of domains like');
    }

    private function emailDeliveryIsResolved(User $recipient, array $sentByUser, array $terminalAtByUser): bool
    {
        $recipientId = (string) $recipient->id;

        return ! $recipient->wantsReminderEmailNotifications()
            || ! empty($sentByUser[$recipientId])
            || ! empty($terminalAtByUser[$recipientId]);
    }

    private function pushDeliveryIsResolved(User $recipient, array $sentByUser): bool
    {
        $recipientId = (string) $recipient->id;

        return ! $recipient->wantsReminderPushNotifications()
            || ! empty($sentByUser[$recipientId])
            || $recipient->pushNotificationDeviceTokens->where('enabled', true)->isEmpty();
    }

    private function resolveNotificationChannels(Reminder $reminder): void
    {
        $metadata = $reminder->metadata ?? [];
        $resolvedAt = now()->toIso8601String();
        $metadata['email_notification_resolved_at'] ??= $resolvedAt;
        $metadata['push_notification_resolved_at'] ??= $resolvedAt;

        if ($metadata !== ($reminder->metadata ?? [])) {
            $reminder->forceFill(['metadata' => $metadata])->save();
        }
    }

    private function notificationRecipients(Reminder $reminder)
    {
        $metadata = $reminder->metadata ?? [];
        $workspaceId = (int) ($reminder->workspace_id ?? 0);
        $recipientsByWorkspace = is_array($metadata['notification_recipients_by_workspace'] ?? null)
            ? $metadata['notification_recipients_by_workspace']
            : [];

        if ($workspaceId > 0 && array_key_exists((string) $workspaceId, $recipientsByWorkspace)) {
            $ids = $this->normalizeRecipientIds($recipientsByWorkspace[(string) $workspaceId]);

            return $this->activeWorkspaceUsers($ids, $workspaceId);
        }

        if ($workspaceId > 0 && array_key_exists('notification_recipient_user_ids', $metadata)) {
            $ids = $this->normalizeRecipientIds($metadata['notification_recipient_user_ids']);

            return $this->activeWorkspaceUsers($ids, $workspaceId);
        }

        $fallbackId = (int) ($reminder->user_id ?? 0);
        if ($fallbackId <= 0) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()
            ->whereKey($fallbackId)
            ->with('pushNotificationDeviceTokens')
            ->get();
    }

    private function activeWorkspaceUsers(array $ids, int $workspaceId)
    {
        if ($ids === []) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereHas('workspaceMemberships', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active'))
            ->with('pushNotificationDeviceTokens')
            ->get();
    }

    private function normalizeRecipientIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function notifyDashboard(Reminder $reminder, string $channel, User $recipient): void
    {
        app(DashboardChangeNotifier::class)->notify(
            userId: $recipient->id,
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
