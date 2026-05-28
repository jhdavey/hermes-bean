<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'onboard_complete', 'is_admin', 'subscription_tier', 'default_workspace_id', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboard_complete' => 'boolean',
            'is_admin' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function subscriptionTier(): string
    {
        return (string) ($this->subscription_tier ?: 'free');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function betaUser(): HasOne
    {
        return $this->hasOne(BetaUser::class);
    }

    public function issueReports(): HasMany
    {
        return $this->hasMany(IssueReport::class);
    }

    public function getNotificationPreferencesAttribute($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return array_merge(self::defaultNotificationPreferences(), is_array($decoded) ? $decoded : []);
    }

    public static function defaultNotificationPreferences(): array
    {
        return [
            'reminder_push' => true,
            'reminder_email' => true,
        ];
    }

    public function wantsReminderEmailNotifications(): bool
    {
        return (bool) ($this->notification_preferences['reminder_email'] ?? true);
    }

    public function wantsReminderPushNotifications(): bool
    {
        return (bool) ($this->notification_preferences['reminder_push'] ?? true);
    }

    public function conversationSessions(): HasMany
    {
        return $this->hasMany(ConversationSession::class);
    }

    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function aiUsageAlerts(): HasMany
    {
        return $this->hasMany(AiUsageAlert::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function googleCalendarConnection(): HasOne
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(Blocker::class);
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot(['role', 'status', 'invited_email', 'accepted_at'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }
}
