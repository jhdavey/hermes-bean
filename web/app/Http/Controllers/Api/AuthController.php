<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\BetaUser;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\GoogleCalendarConnection;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ResetPasswordLink;
use App\Services\AgentProfileService;
use App\Services\WelcomeConversationService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
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
        if ((bool) config('services.beta.enabled', true)) {
            BetaUser::firstOrCreate(
                ['user_id' => $user->id],
                ['status' => 'active', 'source' => 'self_signup', 'started_at' => now()]
            );
        }
        app(AgentProfileService::class)->ensureForUser($user);
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        app(WelcomeConversationService::class)->ensureForUser($user);

        return response()->json(['data' => [
            'user' => $this->hydratedUser($user),
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
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        app(WelcomeConversationService::class)->ensureForUser($user);

        return response()->json(['data' => [
            'user' => $this->hydratedUser($user),
            'token' => $this->issueToken($user),
        ]]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $user->notify(new ResetPasswordLink(PasswordBroker::broker()->createToken($user)));
        }

        return response()->json([
            'message' => 'If an account exists for that email, we sent a password reset link.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hydratedUser($request->user())]);
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
            'tts_provider' => ['sometimes', 'string', Rule::in(['browser', 'openai'])],
            'tts_openai_api_key' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tts_clear_openai_key' => ['sometimes', 'boolean'],
            'tts_openai_voice' => ['sometimes', 'string', Rule::in(['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'])],
            'tts_openai_instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.reminder_push' => ['sometimes', 'boolean'],
            'notification_preferences.reminder_email' => ['sometimes', 'boolean'],
        ]);

        $profileKeys = ['agent_personality', 'onboarding_priorities', 'onboarding_context'];
        $ttsKeys = ['tts_provider', 'tts_openai_api_key', 'tts_clear_openai_key', 'tts_openai_voice', 'tts_openai_instructions'];
        $profileData = collect($data)->only($profileKeys)->all();
        $ttsData = collect($data)->only($ttsKeys)->all();
        $userData = collect($data)->only(['name', 'email'])->all();
        if (array_key_exists('notification_preferences', $data)) {
            $userData['notification_preferences'] = array_merge(
                User::defaultNotificationPreferences(),
                $user->notification_preferences ?? [],
                $data['notification_preferences']
            );
        }
        $user->fill($userData);
        $user->save();

        if ($profileData !== [] || $ttsData !== []) {
            $workspaceService = app(WorkspaceService::class);
            $activeWorkspace = $workspaceService->resolveWorkspace($user->fresh(), $data['workspace_id'] ?? null);
            $profile = app(AgentProfileService::class)->ensureForWorkspace($activeWorkspace, $user);
            $profiles = app(AgentProfileService::class);
            if ($profileData !== []) {
                $profiles->applyOnboarding($profile, $data, 'settings');
                $user->forceFill(['onboard_complete' => true])->save();
                $profile = $profile->refresh();
            }
            if ($ttsData !== []) {
                $profile = $profiles->updateTextToSpeechSettings($profile, $data);
            }
            $user->unsetRelation('agentProfile');
        }

        return response()->json(['data' => $this->hydratedUser($user)]);
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
            'agent_profile' => tap(AgentProfile::where('user_id', $user->id)->first(), fn ($profile) => $profile ? app(AgentProfileService::class)->exposePublicSettings($profile) : null),
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

    private function hydratedUser(User $user): User
    {
        $workspaceService = app(WorkspaceService::class);
        $agentProfiles = app(AgentProfileService::class);

        $agentProfiles->ensureForUser($user);
        $workspaceService->ensurePersonalWorkspaceForUser($user);

        $user = $user->fresh();
        $user->unsetRelation('agentProfile');
        $user->load('agentProfile');

        $personalWorkspace = Workspace::where('personal_owner_user_id', $user->id)->first();
        $activeWorkspace = $workspaceService->resolveWorkspace($user);
        $activeProfile = $agentProfiles->ensureForWorkspace($activeWorkspace, $user);
        $user = $agentProfiles->syncUserOnboardingFlag($user, $activeProfile);
        $activeProfile = $activeProfile->refresh();
        $user->unsetRelation('agentProfile');
        $user->load('agentProfile');
        if ($user->agentProfile) {
            $agentProfiles->exposePublicSettings($user->agentProfile);
        }
        $agentProfiles->exposePublicSettings($activeProfile);
        $betaUser = BetaUser::where('user_id', $user->id)->where('status', 'active')->first();

        $user->setAttribute('personal_workspace', $personalWorkspace);
        $user->setAttribute('active_workspace', $activeWorkspace);
        $user->setAttribute('workspaces', $workspaceService->accessibleWorkspaces($user));
        $user->setAttribute('active_workspace_agent_profile', $activeProfile);
        $user->setAttribute('needs_bean_onboarding', $agentProfiles->needsOnboarding($user, $activeProfile));
        $user->setAttribute('bean_preferences_ready', $agentProfiles->preferencesReady($activeProfile));
        $user->setAttribute('is_beta', $betaUser !== null);
        $user->setAttribute('beta_user', $betaUser);

        return $user;
    }
}
