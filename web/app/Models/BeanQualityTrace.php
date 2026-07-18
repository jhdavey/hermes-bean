<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanQualityTrace extends Model
{
    protected $fillable = [
        'bean_run_id',
        'user_id',
        'workspace_id',
        'mode',
        'intent',
        'actions',
        'time_label',
        'tool_results_count',
        'user_message',
        'assistant_answer',
        'quality_flags',
        'latency_ms',
        'voice',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'quality_flags' => 'array',
            'latency_ms' => 'integer',
            'tool_results_count' => 'integer',
            'voice' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BeanRun::class, 'bean_run_id');
    }
}
