<?php

namespace App\Models;

use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VoiceRealtimeCommand extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'voice_realtime_session_id',
        'voice_turn_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'command_id',
        'command_type',
        'purpose',
        'speech_item_id',
        'controller_generation',
        'approved_text_hash',
        'payload',
        'status',
        'attempts',
        'sending_lease_owner',
        'provider_response_id',
        'available_at',
        'sending_at',
        'sent_at',
        'acknowledged_at',
        'failed_at',
        'error',
    ];

    protected static function booted(): void
    {
        static::updating(function (VoiceRealtimeCommand $command): void {
            foreach ([
                'voice_realtime_session_id',
                'voice_turn_id',
                'user_id',
                'workspace_id',
                'conversation_session_id',
                'command_id',
                'command_type',
                'purpose',
                'speech_item_id',
                'controller_generation',
                'approved_text_hash',
                'payload',
            ] as $attribute) {
                if ($command->isDirty($attribute)) {
                    throw new LogicException("Realtime voice command {$attribute} is immutable.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'command_type' => VoiceRealtimeCommandType::class,
            'status' => VoiceRealtimeCommandStatus::class,
            'controller_generation' => 'integer',
            'attempts' => 'integer',
            'payload' => 'array',
            'available_at' => 'datetime',
            'sending_at' => 'datetime',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function realtimeSession(): BelongsTo
    {
        return $this->belongsTo(VoiceRealtimeSession::class, 'voice_realtime_session_id');
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(VoiceTurn::class, 'voice_turn_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }
}
