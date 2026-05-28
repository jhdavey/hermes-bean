<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueReport extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'beta_user_id',
        'status',
        'message',
        'page_url',
        'user_agent',
        'screenshots',
        'metadata',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'screenshots' => 'array',
            'metadata' => 'array',
            'resolved_at' => 'datetime',
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

    public function betaUser(): BelongsTo
    {
        return $this->belongsTo(BetaUser::class);
    }
}
