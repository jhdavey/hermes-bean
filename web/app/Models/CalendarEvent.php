<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'conversation_session_id', 'title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'starts_at', 'ends_at', 'status', 'metadata', 'google_event_id', 'google_calendar_id', 'google_updated_at', 'outlook_event_id', 'outlook_calendar_id', 'outlook_updated_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_critical' => 'boolean', 'metadata' => 'array', 'google_updated_at' => 'datetime', 'outlook_updated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
