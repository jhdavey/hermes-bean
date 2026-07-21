<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanUsageRecord extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'bean_session_id',
        'bean_run_id',
        'bean_voice_event_id',
        'provider',
        'service',
        'usage_type',
        'model',
        'source',
        'external_id',
        'unit',
        'quantity',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits',
        'estimated_cost_usd',
        'is_estimate',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'credits' => 'float',
            'estimated_cost_usd' => 'float',
            'is_estimate' => 'boolean',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BeanSession::class, 'bean_session_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BeanRun::class, 'bean_run_id');
    }

    public function voiceEvent(): BelongsTo
    {
        return $this->belongsTo(BeanVoiceEvent::class, 'bean_voice_event_id');
    }
}
