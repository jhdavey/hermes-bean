<?php

namespace App\Notifications;

use App\Models\Reminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReminderDueNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Reminder $reminder) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $time = $this->reminder->remind_at?->timezone(config('app.timezone'))->format('M j, Y g:i A');

        return (new MailMessage)
            ->subject('Reminder: '.$this->reminder->title)
            ->view('mail.reminder-due', [
                'logoUrl' => asset('images/bean-logo-black.png'),
                'reminder' => $this->reminder,
                'time' => $time,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reminder_id' => $this->reminder->id,
            'title' => $this->reminder->title,
            'remind_at' => $this->reminder->remind_at?->toIso8601String(),
        ];
    }
}
