<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'calendar_event_id', 'title', 'notes', 'category', 'color', 'is_critical', 'remind_at', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'is_critical' => 'boolean', 'metadata' => 'array'];
    }
}
