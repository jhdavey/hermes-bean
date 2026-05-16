<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMembership extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'role', 'status', 'invited_by_user_id', 'invited_email', 'accepted_at', 'metadata'];

    protected $hidden = ['metadata'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'metadata' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
