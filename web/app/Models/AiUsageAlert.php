<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageAlert extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'scope_type',
        'scope_id',
        'alert_type',
        'severity',
        'period_start',
        'period_end',
        'threshold_value',
        'observed_value',
        'message',
        'metadata',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'threshold_value' => 'decimal:4',
            'observed_value' => 'decimal:4',
            'metadata' => 'array',
            'acknowledged_at' => 'datetime',
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
}
