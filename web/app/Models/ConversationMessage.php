<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'client_turn_id', 'role', 'origin', 'display_mode', 'content', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @param Builder<ConversationMessage> $query */
    public function scopeVisibleInChat(Builder $query): Builder
    {
        return $query->where('display_mode', 'chat');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
