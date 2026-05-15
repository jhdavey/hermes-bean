<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceItemLink extends Model
{
    protected $fillable = ['source_workspace_id', 'target_workspace_id', 'source_type', 'source_id', 'target_type', 'target_id', 'link_type', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function sourceWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'source_workspace_id');
    }

    public function targetWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'target_workspace_id');
    }
}
