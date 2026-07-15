<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceTurnEvent extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'voice_turn_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'sequence',
        'event_type',
        'from_state',
        'to_state',
        'version',
        'source',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'version' => 'integer',
            'payload' => 'array',
        ];
    }

    /** @param Builder<VoiceTurnEvent> $query */
    public function scopeFinalAudioStarted(Builder $query): Builder
    {
        return $query->where(function (Builder $events): void {
            $events->where('event_type', 'final_audio_started')
                ->orWhere(function (Builder $playback): void {
                    $playback->where('event_type', 'playback_started')
                        ->where('payload->purpose', 'final');
                });
        });
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(VoiceTurn::class, 'voice_turn_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
