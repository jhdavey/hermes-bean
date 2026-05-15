<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'starts_at', 'ends_at', 'status', 'metadata', 'google_event_id', 'google_updated_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_critical' => 'boolean', 'metadata' => 'array', 'google_updated_at' => 'datetime'];
    }
}
