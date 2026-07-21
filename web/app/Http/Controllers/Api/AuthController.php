<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\DailyStickyNote;
use App\Models\EarlyAccessSignup;
use App\Models\EventCategory;
use App\Models\GoogleCalendarConnection;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ResetPasswordLink;
use App\Rules\ClientTimezone;
use App\Services\Bean\HermesUserHomeService;
use App\Services\CouponCodeService;
use App\Services\PlanLimitService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    private const THEME_KEYS = [
        'green',
        'gray',
        'blue',
        'purple',
        'pink',
        'red',
        'orange',
        'gold',
        'teal',
        'indigo',
    ];

    private const THEME_MODE_KEYS = [
        'auto',
        'light',
        'dark',
    ];

    private const MAP_APP_KEYS = [
        'google',
        'apple',
    ];

    public function emailAvailability(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        return response()->json([
            'data' => [
                'email' => $data['email'],
                'available' => ! User::where('email', $data['email'])->exists(),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'name' => trim((string) $request->input('name', '')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'plan' => ['sometimes', 'nullable', Rule::in(['base', 'premium', 'pro'])],
            'billing_interval' => ['sometimes', 'nullable', Rule::in(['monthly', 'yearly'])],
            'theme_mode' => ['sometimes', 'string', Rule::in(self::THEME_MODE_KEYS)],
            'timezone' => ['sometimes', 'nullable', new ClientTimezone],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'subscription_tier' => 'base',
                'theme_mode' => $data['theme_mode'] ?? 'light',
                'timezone' => $data['timezone'] ?? null,
                'onboard_complete' => true,
            ]);

            app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
            app(HermesUserHomeService::class)->ensureForUser($user);
            EarlyAccessSignup::updateOrCreate(
                ['email' => $user->email],
                [
                    'name' => $user->name,
                    'use_case' => null,
                    'requested_plan' => $data['plan'] ?? null,
                    'source' => 'app_register',
                ],
            );

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => [
            'user' => $this->hydratedUser($user),
            'token' => $this->issueToken($user),
            'selected_plan' => $data['plan'] ?? null,
            'selected_billing_interval' => $data['billing_interval'] ?? 'monthly',
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

        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        app(HermesUserHomeService::class)->ensureForUser($user);

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
            'theme' => ['sometimes', 'string', Rule::in(self::THEME_KEYS)],
            'theme_mode' => ['sometimes', 'string', Rule::in(self::THEME_MODE_KEYS)],
            'command_center_label' => ['sometimes', 'required', 'string', 'max:80'],
            'preferred_map_app' => ['sometimes', 'string', Rule::in(self::MAP_APP_KEYS)],
            'timezone' => ['sometimes', 'nullable', new ClientTimezone],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.reminder_push' => ['sometimes', 'boolean'],
            'notification_preferences.reminder_email' => ['sometimes', 'boolean'],
        ]);

        $userData = collect($data)->only(['name', 'email', 'theme', 'theme_mode', 'command_center_label', 'preferred_map_app', 'timezone'])->all();
        if (array_key_exists('notification_preferences', $data)) {
            $planLimits = app(PlanLimitService::class);
            if (($data['notification_preferences']['reminder_email'] ?? false) && ! $planLimits->canUseEmailReminders($user)) {
                return $planLimits->limitResponse('Email reminders are available on Premium, Pro, and Enterprise plans.');
            }

            $userData['notification_preferences'] = array_merge(
                User::defaultNotificationPreferences(),
                $user->notification_preferences ?? [],
                $data['notification_preferences']
            );
        }
        $user->fill($userData);
        $user->save();

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
            'tasks' => Task::where('user_id', $user->id)->orderBy('id')->get(),
            'reminders' => Reminder::where('user_id', $user->id)->orderBy('id')->get(),
            'calendar_events' => CalendarEvent::where('user_id', $user->id)->orderBy('id')->get(),
            'daily_sticky_notes' => DailyStickyNote::where('user_id', $user->id)->orderBy('note_date')->get(),
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
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            Workspace::where('personal_owner_user_id', $user->id)->delete();
            $user->delete();
        });
        app(HermesUserHomeService::class)->deleteForUser($user);

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
        $workspaceService->ensurePersonalWorkspaceForUser($user);
        $user = app(CouponCodeService::class)->syncBaseCompAccess($user);

        $user = $user->fresh();
        $personalWorkspace = Workspace::where('personal_owner_user_id', $user->id)->first();
        $activeWorkspace = $workspaceService->resolveWorkspace($user);
        $earlyAccessSignup = EarlyAccessSignup::where('email', $user->email)->first();
        $user->setAttribute('personal_workspace', $personalWorkspace);
        $user->setAttribute('active_workspace', $activeWorkspace);
        $user->setAttribute('workspaces', $workspaceService->accessibleWorkspaces($user));
        $user->setAttribute('is_early_access', $earlyAccessSignup !== null);
        $user->setAttribute('early_access_signup', $earlyAccessSignup);
        $user->setAttribute('email_verified', $user->hasVerifiedEmail());
        $user->setAttribute('plan_limits', app(PlanLimitService::class)->publicLimitsFor($user));

        return $user;
    }
}
