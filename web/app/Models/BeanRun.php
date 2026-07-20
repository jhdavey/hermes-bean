<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeanRun extends Model
{
    protected $fillable = ['bean_session_id', 'user_id', 'workspace_id', 'status', 'mode', 'model', 'input', 'output', 'error', 'metadata', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BeanSession::class, 'bean_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(BeanToolCall::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(BeanActivityEvent::class);
    }

    public function voiceEvents(): HasMany
    {
        return $this->hasMany(BeanVoiceEvent::class);
    }
}
