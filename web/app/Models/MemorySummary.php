<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemorySummary extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'summary_type',
        'period_key',
        'title',
        'summary',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
