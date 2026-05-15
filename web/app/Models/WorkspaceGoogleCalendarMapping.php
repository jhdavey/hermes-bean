<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceGoogleCalendarMapping extends Model
{
    protected $fillable = ['workspace_id', 'google_calendar_connection_id', 'google_calendar_id', 'sync_direction', 'is_default_export', 'settings'];

    protected function casts(): array
    {
        return ['is_default_export' => 'boolean', 'settings' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }
}
