<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanVoiceBridgeSession extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'bean_session_id',
        'workspace_id',
        'conversation_id',
        'client_timezone',
        'status',
        'metadata',
        'last_transcript_at',
        'connected_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_transcript_at' => 'datetime',
            'connected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beanSession(): BelongsTo
    {
        return $this->belongsTo(BeanSession::class);
    }
}
