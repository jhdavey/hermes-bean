<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationSession extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'title', 'status', 'runtime_mode', 'metadata', 'last_activity_at'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(Blocker::class);
    }
}
