<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryEvent extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'conversation_message_id',
        'assistant_message_id',
        'event_type',
        'status',
        'content',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
