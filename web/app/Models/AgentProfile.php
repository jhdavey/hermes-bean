<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'slug',
        'display_name',
        'status',
        'provider',
        'model',
        'router_mode',
        'runtime_home',
        'settings',
        'tool_policy',
        'approval_policy',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'tool_policy' => 'array',
            'approval_policy' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
