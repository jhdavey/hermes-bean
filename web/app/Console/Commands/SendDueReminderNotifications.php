<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Notifications\ReminderDueNotification;
use Illuminate\Console\Command;

class SendDueReminderNotifications extends Command
{
    protected $signature = 'reminders:send-due-notifications {--limit=200}';

    protected $description = 'Send email notifications for due HeyBean reminders.';

    public function handle(): int
    {
        $sent = 0;
        Reminder::query()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            })
            ->where(function ($query): void {
                $query->whereNull('metadata->email_notification_sent_at');
            })
            ->with('user')
            ->orderBy('remind_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Reminder $reminder) use (&$sent): void {
                $user = $reminder->user;
                if (! $user || ! $user->wantsReminderEmailNotifications()) {
                    return;
                }

                $user->notify(new ReminderDueNotification($reminder));
                $metadata = $reminder->metadata ?? [];
                $metadata['email_notification_sent_at'] = now()->toIso8601String();
                $reminder->forceFill(['metadata' => $metadata])->save();
                $sent++;
            });

        $this->info("Sent {$sent} reminder email notification(s).");

        return self::SUCCESS;
    }
}
