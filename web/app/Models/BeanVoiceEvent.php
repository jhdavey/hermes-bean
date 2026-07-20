<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanVoiceEvent extends Model
{
    protected $fillable = [
        'user_id',
        'bean_session_id',
        'bean_run_id',
        'event_type',
        'mode',
        'source',
        'label',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BeanSession::class, 'bean_session_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BeanRun::class, 'bean_run_id');
    }
}
