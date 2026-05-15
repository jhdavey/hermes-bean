<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Task extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'conversation_session_id', 'title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'completed_at', 'metadata'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'is_critical' => 'boolean', 'metadata' => 'array'];
    }

    public function isCompleted(): bool
    {
        return in_array(strtolower(str_replace('_', '-', (string) $this->status)), ['completed', 'complete', 'done'], true);
    }

    public function scopeVisibleInActiveViews(Builder $query): Builder
    {
        $today = Carbon::today();

        return $query->where(function (Builder $query) use ($today): void {
            $query->whereNull('due_at')
                ->orWhere('due_at', '>=', $today)
                ->orWhereNotNull('metadata->recurrence')
                ->orWhereNotNull('metadata->recurring')
                ->orWhereNotNull('metadata->rrule');
        });
    }
}
