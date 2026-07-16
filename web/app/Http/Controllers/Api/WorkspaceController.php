<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\GoogleCalendarSyncService;
use App\Services\PlanLimitService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly GoogleCalendarSyncService $googleCalendar,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspaces->accessibleWorkspaces($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $currentWorkspaceCount = $this->workspaces->accessibleWorkspaces($request->user())->count();
        if ($response = $this->planLimits->enforceWorkspaceLimit($request->user(), $currentWorkspaceCount)) {
            return $response;
        }

        $workspace = $this->workspaces->createHousehold($request->user(), $data['name']);
        return response()->json(['data' => $workspace->load('memberships.user')], 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->workspaces->authorizeMember($request->user(), $workspace);
        return response()->json(['data' => $workspace->load('memberships.user', 'googleCalendarMappings')]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->workspaces->authorizeOwner($request->user(), $workspace);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);
        $workspace->fill($data)->save();

        return response()->json(['data' => $workspace->refresh()]);
    }

    public function invite(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        try {
            $membership = $this->workspaces->invite($request->user(), $workspace, $data['email']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $membership], 201);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $membership = $this->workspaces->acceptInvite($request->user(), $token);

        return response()->json(['data' => $membership->load('workspace')]);
    }

    public function updateMember(Request $request, Workspace $workspace, WorkspaceMembership $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:owner,member']]);
        try {
            $membership = $this->workspaces->updateMemberRole($request->user(), $workspace, $member, $data['role']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $membership]);
    }

    public function destroyMember(Request $request, Workspace $workspace, WorkspaceMembership $member): JsonResponse
    {
        try {
            $this->workspaces->removeMember($request->user(), $workspace, $member);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(status: 204);
    }

    public function leave(Request $request, Workspace $workspace): JsonResponse
    {
        try {
            $this->workspaces->leave($request->user(), $workspace);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(status: 204);
    }

    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['workspace_id' => ['required', 'integer', 'exists:workspaces,id']]);
        $workspace = $this->workspaces->setDefaultWorkspace($request->user(), Workspace::findOrFail($data['workspace_id']));

        return response()->json(['data' => $workspace]);
    }

    public function syncAll(Request $request, Workspace $source, WorkspaceItemSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'target_workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'resource_types' => ['nullable', 'array'],
            'resource_types.*' => ['in:tasks,reminders,calendar_events'],
        ]);
        $target = Workspace::findOrFail($data['target_workspace_id']);
        $this->workspaces->authorizeMember($request->user(), $source);
        $this->workspaces->authorizeMember($request->user(), $target);
        if ($source->type === 'household') {
            $this->workspaces->authorizeOwner($request->user(), $source);
        }
        if ($target->type === 'household') {
            $this->workspaces->authorizeOwner($request->user(), $target);
        }

        return response()->json(['data' => $sync->syncAll($source, $target, $request->user(), $data['resource_types'] ?? ['tasks', 'reminders', 'calendar_events'])]);
    }

    public function calendars(Request $request, Workspace $workspace): JsonResponse
    {
        $this->workspaces->authorizeMember($request->user(), $workspace);
        $connection = $request->user()->googleCalendarConnection;
        abort_unless($connection, 422, 'Calendar sync is not connected.');
        $data = $request->validate([
            'google_calendar_ids' => ['present', 'array'],
            'google_calendar_ids.*' => ['string'],
            'default_export_calendar_id' => ['nullable', 'string'],
        ]);
        if ($response = $this->planLimits->enforceCalendarSelectionLimit($request->user(), count(array_unique($data['google_calendar_ids'])))) {
            return $response;
        }

        $workspace->googleCalendarMappings()->delete();
        $this->googleCalendar->clearWorkspaceSyncTokens($connection, $workspace);
        $settings = $workspace->settings ?? [];
        $settings['google_calendar_mappings_configured'] = true;
        $workspace->forceFill(['settings' => $settings])->save();
        foreach ($data['google_calendar_ids'] as $calendarId) {
            $workspace->googleCalendarMappings()->create([
                'google_calendar_connection_id' => $connection->id,
                'google_calendar_id' => $calendarId,
                'sync_direction' => 'both',
                'is_default_export' => ($data['default_export_calendar_id'] ?? null) === $calendarId,
            ]);
        }

        return response()->json(['data' => $workspace->googleCalendarMappings()->get()]);
    }
}
