<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanConfirmationRequest extends Model
{
    protected $fillable = ['bean_session_id', 'bean_run_id', 'user_id', 'workspace_id', 'action', 'arguments', 'summary', 'status', 'approved_at', 'rejected_at'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function session(): BelongsTo { return $this->belongsTo(BeanSession::class, 'bean_session_id'); }
    public function run(): BelongsTo { return $this->belongsTo(BeanRun::class, 'bean_run_id'); }
}
