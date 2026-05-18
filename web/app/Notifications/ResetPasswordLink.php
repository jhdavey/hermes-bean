<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your HeyBean password')
            ->greeting('Reset your HeyBean password')
            ->line('We received a request to reset the password for your HeyBean account.')
            ->action('Reset password', $this->resetUrl($notifiable))
            ->line('After your password is reset, you’ll be sent back to the app login screen.')
            ->line('If you did not request this, you can ignore this email.');
    }

    public function resetUrl(object $notifiable): string
    {
        /** @var User $notifiable */
        return route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
    }
}
