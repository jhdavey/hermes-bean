<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssistantRun extends Model
{
    protected $fillable = [
        'voice_turn_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'user_message_id',
        'assistant_message_id',
        'source',
        'lane',
        'handler',
        'label',
        'priority',
        'resource_lock_key',
        'idempotency_key',
        'hard_deadline_at',
        'last_progress_at',
        'dispatch_requested_at',
        'status',
        'input',
        'metadata',
        'result',
        'error',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (AssistantRun $run): void {
            if ($run->getOriginal('voice_turn_id') === null) {
                return;
            }

            foreach (['voice_turn_id', 'lane', 'handler', 'idempotency_key'] as $attribute) {
                if ($run->isDirty($attribute)) {
                    throw new LogicException("Browser voice run {$attribute} is immutable after admission.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'result' => 'array',
            'priority' => 'integer',
            'hard_deadline_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'dispatch_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }

    public function voiceTurn(): BelongsTo
    {
        return $this->belongsTo(VoiceTurn::class);
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
