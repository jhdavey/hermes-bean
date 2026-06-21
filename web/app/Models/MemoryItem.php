<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'created_by_user_id',
        'type',
        'status',
        'visibility',
        'title',
        'content',
        'summary',
        'confidence',
        'importance',
        'source_type',
        'source_id',
        'last_seen_at',
        'last_verified_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'importance' => 'integer',
            'last_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(MemoryLink::class);
    }
}
