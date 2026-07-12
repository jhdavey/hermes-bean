<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    protected $fillable = ['type', 'name', 'slug', 'personal_owner_user_id', 'created_by_user_id', 'status', 'settings', 'metadata'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'metadata' => 'array'];
    }

    public function personalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'personal_owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', 'active');
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
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

    public function voiceTurns(): HasMany
    {
        return $this->hasMany(VoiceTurn::class);
    }

    public function eventCategories(): HasMany
    {
        return $this->hasMany(EventCategory::class);
    }

    public function googleCalendarMappings(): HasMany
    {
        return $this->hasMany(WorkspaceGoogleCalendarMapping::class);
    }
}
