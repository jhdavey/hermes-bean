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
        return $this->status === 'completed';
    }

    public function scopeVisibleInActiveViews(Builder $query): Builder
    {
        $today = Carbon::today();
        $completedStatus = 'completed';

        return $query->where(function (Builder $query) use ($today, $completedStatus): void {
            $query->whereNull('due_at')
                ->orWhere('due_at', '>=', $today)
                ->orWhere(function (Builder $query) use ($today, $completedStatus): void {
                    $query->where('due_at', '<', $today)
                        ->whereNull('completed_at')
                        ->where(function (Builder $query) use ($completedStatus): void {
                            $query->whereNull('status')
                                ->orWhere('status', '!=', $completedStatus);
                        });
                })
                ->orWhere(function (Builder $query): void {
                    $query->whereNotNull('metadata->recurrence')
                        ->where('metadata->recurrence', '!=', 'none');
                });
        });
    }
}
