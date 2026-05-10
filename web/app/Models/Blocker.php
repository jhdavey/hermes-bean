<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Blocker extends Model
{
    protected $fillable = ['conversation_session_id', 'reason', 'status', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
