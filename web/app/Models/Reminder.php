<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'title', 'notes', 'remind_at', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'metadata' => 'array'];
    }
}
