<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class SubscriptionReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $type,
        private readonly string $plan,
        private readonly ?Carbon $currentPeriodEnd = null,
        private readonly ?Carbon $trialEndsAt = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->greeting())
            ->line($this->primaryLine());

        if ($this->trialEndsAt) {
            $message->line('Your free trial runs through '.$this->formatDate($this->trialEndsAt).'.');
        } elseif ($this->currentPeriodEnd) {
            $message->line('Your current access runs through '.$this->formatDate($this->currentPeriodEnd).'.');
        }

        return $message
            ->line('Stripe securely handles payment processing. HeyBean only stores your subscription status and safe payment summaries.')
            ->line('If anything looks wrong, reply to this email or contact support@heybean.org.');
    }

    private function subject(): string
    {
        return match ($this->type) {
            'upgrade' => 'Your HeyBean subscription was upgraded',
            'cancellation' => 'Your HeyBean renewal was canceled',
            default => 'Your HeyBean subscription is active',
        };
    }

    private function greeting(): string
    {
        return match ($this->type) {
            'upgrade' => 'Subscription upgraded',
            'cancellation' => 'Renewal canceled',
            default => 'Subscription started',
        };
    }

    private function primaryLine(): string
    {
        $plan = $this->planLabel();

        return match ($this->type) {
            'upgrade' => 'Your HeyBean subscription is now on the '.$plan.' plan.',
            'cancellation' => 'Renewal has been canceled for your '.$plan.' plan. You will not be charged again for this subscription.',
            default => 'Your HeyBean '.$plan.' subscription is set up.',
        };
    }

    private function planLabel(): string
    {
        return match (strtolower($this->plan)) {
            'premium' => 'Premium',
            'pro' => 'Pro',
            default => 'Base',
        };
    }

    private function formatDate(Carbon $date): string
    {
        return $date->copy()->timezone(config('app.timezone'))->format('M j, Y');
    }
}
