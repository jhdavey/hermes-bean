<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\GoogleCalendarConnection;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentProfileService;
use App\Services\OnboardingSeedService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
        $profile = app(AgentProfileService::class)->ensureForUser($user);
        if (config('hermes_bean.seed_onboarding_resources', true)) {
            app(OnboardingSeedService::class)->ensureForUser($user);
        }
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $user->refresh()->load('agentProfile');

        return response()->json(['data' => [
            'user' => $user,
            'token' => $this->issueToken($user),
        ]], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        app(AgentProfileService::class)->ensureForUser($user);
        $user->unsetRelation('agentProfile');
        $user->load('agentProfile');
        if (config('hermes_bean.seed_onboarding_resources', true)) {
            app(OnboardingSeedService::class)->ensureForUser($user);
        }
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        return response()->json(['data' => [
            'user' => $user,
            'token' => $this->issueToken($user),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceService = app(WorkspaceService::class);
        app(AgentProfileService::class)->ensureForUser($user);
        $workspaceService->ensurePersonalWorkspaceForUser($user);
        $user->unsetRelation('agentProfile');
        $user->load('agentProfile');
        $personalWorkspace = \App\Models\Workspace::where('personal_owner_user_id', $user->id)->first();
        $activeWorkspace = $workspaceService->resolveWorkspace($user->fresh());
        $agentProfile = app(AgentProfileService::class)->ensureForWorkspace($activeWorkspace, $user);
        $user->setAttribute('personal_workspace', $personalWorkspace);
        $user->setAttribute('active_workspace', $activeWorkspace);
        $user->setAttribute('workspaces', $workspaceService->accessibleWorkspaces($user));
        $user->setAttribute('active_workspace_agent_profile', $agentProfile);

        return response()->json(['data' => $user]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($request->has('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email', ''))),
            ]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'agent_personality' => ['sometimes', 'string', Rule::in(AgentProfileService::personalityKeys())],
            'onboarding_priorities' => ['sometimes', 'array', 'max:5'],
            'onboarding_priorities.*' => ['string', 'max:80'],
            'onboarding_context' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user->fill(collect($data)->only(['name', 'email'])->all());
        $user->save();

        if (array_key_exists('agent_personality', $data) || array_key_exists('onboarding_priorities', $data) || array_key_exists('onboarding_context', $data)) {
            $profile = app(AgentProfileService::class)->ensureForUser($user);
            app(AgentProfileService::class)->applyOnboarding($profile, $data, 'settings');
            $user->forceFill(['onboard_complete' => true])->save();
            $user->unsetRelation('agentProfile');
        }

        $user->load('agentProfile');

        return response()->json(['data' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if ($token) {
            PersonalAccessToken::where('token', hash('sha256', $token))->delete();
        }

        return response()->json(null, 204);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'user' => $user,
            'agent_profile' => AgentProfile::where('user_id', $user->id)->first(),
            'conversation_sessions' => ConversationSession::where('user_id', $user->id)->with(['messages', 'activityEvents', 'blockers'])->orderBy('id')->get(),
            'tasks' => Task::where('user_id', $user->id)->orderBy('id')->get(),
            'reminders' => Reminder::where('user_id', $user->id)->orderBy('id')->get(),
            'calendar_events' => CalendarEvent::where('user_id', $user->id)->orderBy('id')->get(),
            'event_categories' => EventCategory::where('user_id', $user->id)->orderBy('id')->get(),
            'google_calendar' => GoogleCalendarConnection::where('user_id', $user->id)->get([
                'id',
                'user_id',
                'google_account_email',
                'calendar_id',
                'status',
                'sync_token',
                'last_synced_at',
                'last_error_at',
                'metadata',
                'created_at',
                'updated_at',
            ]),
            'approvals' => Approval::where('user_id', $user->id)->orderBy('id')->get(),
            'blockers' => Blocker::where('user_id', $user->id)->orderBy('id')->get(),
            'scheduler_jobs' => SchedulerJobRecord::where('user_id', $user->id)->orderBy('id')->get(),
            'activity_events' => ActivityEvent::where('user_id', $user->id)->orderBy('id')->get(),
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            Workspace::where('personal_owner_user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(null, 204);
    }

    private function issueToken(User $user): string
    {
        $plainToken = bin2hex(random_bytes(32));

        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(config('security.api_token_ttl_days', 90)),
        ]);

        return $plainToken;
    }
}
