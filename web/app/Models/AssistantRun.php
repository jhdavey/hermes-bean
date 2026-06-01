<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantRun extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'user_message_id',
        'assistant_message_id',
        'source',
        'status',
        'input',
        'metadata',
        'result',
        'error',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'assistant_message_id');
    }
}
