<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use Illuminate\Database\Eloquent\Model;

class WorkspaceItemSyncService
{
    public function sync(Model $source, Workspace $targetWorkspace, User $actor): Model
    {
        $sourceWorkspaceId = (int) $source->workspace_id;
        $type = $this->typeFor($source);
        $targetClass = $source::class;
        $link = WorkspaceItemLink::where([
            'source_workspace_id' => $sourceWorkspaceId,
            'target_workspace_id' => $targetWorkspace->id,
            'source_type' => $type,
            'source_id' => $source->id,
            'target_type' => $type,
            'link_type' => 'copy',
        ])->first();

        $target = $link ? $targetClass::find($link->target_id) : null;
        $target ??= $this->existingTargetForCopy($source, $targetWorkspace, $actor);
        $target ??= new $targetClass;
        $target->fill($this->attributesForCopy($source));
        $target->forceFill([
            'workspace_id' => $targetWorkspace->id,
            'user_id' => $actor->id,
            'created_by_user_id' => $actor->id,
        ])->save();

        WorkspaceItemLink::updateOrCreate(
            [
                'source_workspace_id' => $sourceWorkspaceId,
                'target_workspace_id' => $targetWorkspace->id,
                'source_type' => $type,
                'source_id' => $source->id,
                'target_type' => $type,
                'link_type' => 'copy',
            ],
            [
                'target_id' => $target->id,
                'metadata' => ['synced_at' => now()->toISOString()],
            ]
        );

        return $target->refresh();
    }

    public function syncToWorkspaceIds(Model $source, array $workspaceIds, User $actor): array
    {
        $results = [];
        foreach (array_unique(array_map('intval', $workspaceIds)) as $workspaceId) {
            if ($workspaceId <= 0 || (int) $source->workspace_id === $workspaceId) {
                continue;
            }
            $results[] = $this->sync($source, Workspace::findOrFail($workspaceId), $actor);
        }

        return $results;
    }

    public function syncAll(Workspace $source, Workspace $target, User $actor, array $resourceTypes): array
    {
        $summary = ['tasks' => 0, 'reminders' => 0, 'calendar_events' => 0];
        foreach ($resourceTypes as $resourceType) {
            $class = match ($resourceType) {
                'tasks' => Task::class,
                'reminders' => Reminder::class,
                'calendar_events' => CalendarEvent::class,
                default => null,
            };
            if (! $class) {
                continue;
            }
            foreach ($class::where('workspace_id', $source->id)->orderBy('id')->get() as $item) {
                $this->sync($item, $target, $actor);
                $summary[$resourceType]++;
            }
        }

        return $summary;
    }

    private function attributesForCopy(Model $source): array
    {
        $attributes = $source->getAttributes();
        foreach (['id', 'user_id', 'workspace_id', 'created_by_user_id', 'created_at', 'updated_at'] as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    private function existingTargetForCopy(Model $source, Workspace $targetWorkspace, User $actor): ?Model
    {
        if (! $source instanceof CalendarEvent || ! $source->google_event_id) {
            return null;
        }

        return CalendarEvent::query()
            ->where('workspace_id', $targetWorkspace->id)
            ->where('user_id', $actor->id)
            ->where('google_event_id', $source->google_event_id)
            ->where(function ($query) use ($source): void {
                $query->where('google_calendar_id', $source->google_calendar_id);

                if ($source->google_calendar_id === 'primary') {
                    $query->orWhere('google_calendar_id', $source->user?->email);
                } elseif ($source->user?->email && $source->google_calendar_id === $source->user->email) {
                    $query->orWhere('google_calendar_id', 'primary');
                }
            })
            ->first();
    }

    private function typeFor(Model $model): string
    {
        return match (true) {
            $model instanceof Task => 'tasks',
            $model instanceof Reminder => 'reminders',
            $model instanceof CalendarEvent => 'calendar_events',
            default => $model->getTable(),
        };
    }
}
