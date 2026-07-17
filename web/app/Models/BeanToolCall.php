<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanToolCall extends Model
{
    protected $fillable = ['bean_run_id', 'user_id', 'workspace_id', 'action', 'arguments', 'status', 'result', 'error', 'requires_confirmation', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'result' => 'array', 'requires_confirmation' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function run(): BelongsTo { return $this->belongsTo(BeanRun::class, 'bean_run_id'); }
}
