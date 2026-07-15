<?php

namespace App\Models;

use App\Enums\ConversationSessionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConversationSession extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'title', 'status', 'session_kind', 'metadata', 'last_activity_at'];

    protected function casts(): array
    {
        return [
            'session_kind' => ConversationSessionKind::class,
            'metadata' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function assistantRuns(): HasMany
    {
        return $this->hasMany(AssistantRun::class);
    }

    public function voiceTurns(): HasMany
    {
        return $this->hasMany(VoiceTurn::class);
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(Blocker::class);
    }
}
