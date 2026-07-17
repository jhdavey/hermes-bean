<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\DashboardChange;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

class DashboardChangeNotifier
{
    public function modelChanged(Model $model, string $action, array $payload = []): ?DashboardChange
    {
        $resourceType = $this->resourceType($model);
        if ($resourceType === null) {
            return null;
        }

        return $this->notify(
            userId: (int) $model->getAttribute('user_id') ?: null,
            workspaceId: (int) $model->getAttribute('workspace_id') ?: null,
            resourceType: $resourceType,
            action: $action,
            resourceId: (int) $model->getKey(),
            payload: $payload
        );
    }

    public function notify(?int $userId, ?int $workspaceId, string $resourceType, string $action, ?int $resourceId = null, array $payload = []): DashboardChange
    {
        return DashboardChange::create([
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'resource_type' => $resourceType,
            'action' => $action,
            'resource_id' => $resourceId,
            'payload' => $payload ?: null,
        ]);
    }

    private function resourceType(Model $model): ?string
    {
        return match (true) {
            $model instanceof Task => 'task',
            $model instanceof Reminder => 'reminder',
            $model instanceof CalendarEvent => 'calendar_event',
            $model instanceof Note => 'note',
            default => null,
        };
    }
}
