<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStickyNote extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'note_date',
        'content',
    ];

    protected function casts(): array
    {
        return ['note_date' => 'date:Y-m-d'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
