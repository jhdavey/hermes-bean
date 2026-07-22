<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EarlyAccessAvailable extends Notification
{
    use Queueable;

    public function __construct(private readonly bool $accountExists = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $destination = $this->accountExists
            ? url('/login')
            : url('/register?email='.urlencode((string) $notifiable->routeNotificationFor('mail')));

        return (new MailMessage)
            ->subject('Your HeyBean early-access spot is ready')
            ->greeting('Your early-access spot is open')
            ->line('Thanks for waiting while HeyBean onboarded its first groups carefully.')
            ->line($this->accountExists
                ? 'Log in to choose a plan and start your seven-day free trial. Your dashboard and Bean onboarding tour will open after checkout is complete.'
                : 'You can now create your account, choose a plan, and start your seven-day free trial. Your dashboard and Bean onboarding tour will open after checkout is complete.')
            ->action($this->accountExists ? 'Log in to HeyBean' : 'Finish setting up HeyBean', $destination)
            ->line('If you are not ready yet, you can keep this email and return later.');
    }
}
