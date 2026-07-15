<?php

namespace App\Models;

use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class VoiceTurn extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'turn_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'realtime_session_id',
        'provider_input_item_id',
        'user_message_id',
        'final_assistant_message_id',
        'source',
        'client_kind',
        'display_mode',
        'semantic_input',
        'state',
        'version',
        'idempotency_key',
        'acknowledgement_required',
        'acknowledgement_text',
        'acknowledged_at',
        'accepted_at',
        'started_at',
        'first_progress_at',
        'terminal_at',
        'hard_deadline_at',
        'no_progress_deadline_at',
        'failure_category',
        'internal_failure_detail',
        'user_facing_failure_text',
        'side_effect_status',
        'retry_count',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (VoiceTurn $turn): void {
            $immutable = [
                'turn_id',
                'user_id',
                'workspace_id',
                'conversation_session_id',
                'source',
                'client_kind',
                'idempotency_key',
                'acknowledgement_required',
                'acknowledgement_text',
                'accepted_at',
                'hard_deadline_at',
            ];

            foreach ($immutable as $attribute) {
                if ($turn->isDirty($attribute)) {
                    throw new LogicException("Voice turn {$attribute} is immutable after admission.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'state' => VoiceTurnState::class,
            'side_effect_status' => VoiceTurnSideEffectStatus::class,
            'version' => 'integer',
            'retry_count' => 'integer',
            'acknowledgement_required' => 'boolean',
            'acknowledged_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'first_progress_at' => 'datetime',
            'terminal_at' => 'datetime',
            'hard_deadline_at' => 'datetime',
            'no_progress_deadline_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }

    public function realtimeSession(): BelongsTo
    {
        return $this->belongsTo(VoiceRealtimeSession::class, 'realtime_session_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'user_message_id');
    }

    public function finalAssistantMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'final_assistant_message_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AssistantRun::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoiceTurnEvent::class);
    }
}
