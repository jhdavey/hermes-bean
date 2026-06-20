<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'created_by_user_id',
        'note_folder_id',
        'title',
        'body_html',
        'plain_text',
        'body_delta',
        'is_pinned',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['body_delta' => 'array', 'is_pinned' => 'boolean', 'sort_order' => 'integer', 'metadata' => 'array'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(NoteFolder::class, 'note_folder_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
