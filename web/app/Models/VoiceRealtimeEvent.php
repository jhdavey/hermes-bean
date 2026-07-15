<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VoiceRealtimeEvent extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'voice_realtime_session_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'provider_event_id',
        'event_type',
        'provider_input_item_id',
        'provider_response_id',
        'payload',
        'received_at',
        'processing_attempts',
        'processing_lease_owner',
        'processing_started_at',
        'next_attempt_at',
        'processed_at',
        'failed_at',
        'error',
    ];

    protected static function booted(): void
    {
        static::updating(function (VoiceRealtimeEvent $event): void {
            foreach ([
                'voice_realtime_session_id',
                'user_id',
                'workspace_id',
                'conversation_session_id',
                'provider_event_id',
                'event_type',
                'provider_input_item_id',
                'provider_response_id',
                'payload',
                'received_at',
            ] as $attribute) {
                if ($event->isDirty($attribute)) {
                    throw new LogicException("Realtime voice event {$attribute} is immutable.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processing_attempts' => 'integer',
            'processing_started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function realtimeSession(): BelongsTo
    {
        return $this->belongsTo(VoiceRealtimeSession::class, 'voice_realtime_session_id');
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
