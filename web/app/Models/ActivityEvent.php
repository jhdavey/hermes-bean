<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'event_type', 'tool_name', 'status', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
