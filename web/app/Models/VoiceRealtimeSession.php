<?php

namespace App\Models;

use App\Enums\VoiceRealtimeSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class VoiceRealtimeSession extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'public_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'provider_call_id',
        'provider_model',
        'voice',
        'status',
        'controller_generation',
        'lease_owner',
        'lease_expires_at',
        'connect_attempts',
        'reconnect_count',
        'reconnect_not_before_at',
        'sideband_connected_at',
        'last_heartbeat_at',
        'closed_at',
        'failure_category',
        'failure_detail',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (VoiceRealtimeSession $session): void {
            foreach ([
                'public_id',
                'user_id',
                'workspace_id',
                'conversation_session_id',
                'provider_model',
                'voice',
                'controller_generation',
            ] as $attribute) {
                if ($session->isDirty($attribute)) {
                    throw new LogicException("Realtime voice session {$attribute} is immutable.");
                }
            }
            if ($session->getOriginal('provider_call_id') !== null && $session->isDirty('provider_call_id')) {
                throw new LogicException('Realtime voice provider_call_id is immutable after binding.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => VoiceRealtimeSessionStatus::class,
            'controller_generation' => 'integer',
            'connect_attempts' => 'integer',
            'reconnect_count' => 'integer',
            'reconnect_not_before_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'sideband_connected_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @param Builder<VoiceRealtimeSession> $query */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
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

    public function turns(): HasMany
    {
        return $this->hasMany(VoiceTurn::class, 'realtime_session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoiceRealtimeEvent::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(VoiceRealtimeCommand::class);
    }
}
