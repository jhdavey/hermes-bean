<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'title', 'description', 'status', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
