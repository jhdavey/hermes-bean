<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'title', 'type', 'status', 'notes', 'due_at', 'metadata'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'metadata' => 'array'];
    }
}
