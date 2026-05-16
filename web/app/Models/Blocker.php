<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Blocker extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'conversation_session_id', 'reason', 'status', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
