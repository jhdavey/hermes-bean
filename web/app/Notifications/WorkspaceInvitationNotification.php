<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $inviter,
        private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->inviter->name.' invited you to '.$this->workspace->name.' on HeyBean')
            ->greeting('You have a HeyBean household invite')
            ->line($this->inviter->name.' invited you to join '.$this->workspace->name.'.')
            ->line('Accepting this invite adds the household to your HeyBean workspace settings.')
            ->action('Accept invite', $this->acceptUrl())
            ->line('If you were not expecting this invite, you can ignore this email.');
    }

    public function acceptUrl(): string
    {
        return route('workspace-invitations.accept', ['token' => $this->token]);
    }
}
