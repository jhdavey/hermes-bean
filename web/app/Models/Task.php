<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'completed_at', 'metadata'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'is_critical' => 'boolean', 'metadata' => 'array'];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function scopeVisibleInActiveViews(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
