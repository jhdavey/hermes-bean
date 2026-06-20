<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoteFolder extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'name', 'sort_order', 'metadata'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'metadata' => 'array'];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
