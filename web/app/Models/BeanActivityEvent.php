<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeanActivityEvent extends Model
{
    protected $fillable = ['bean_session_id', 'bean_run_id', 'user_id', 'workspace_id', 'type', 'label', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function session(): BelongsTo { return $this->belongsTo(BeanSession::class, 'bean_session_id'); }
    public function run(): BelongsTo { return $this->belongsTo(BeanRun::class, 'bean_run_id'); }
}
