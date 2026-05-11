<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\AgentProfile;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\PersonalAccessToken;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create($data);
        app(AgentProfileService::class)->ensureForUser($user);
        $user->load('agentProfile');

        return response()->json(['data' => [
            'user' => $user,
            'token' => $this->issueToken($user),
        ]], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $user->loadMissing('agentProfile');

        return response()->json(['data' => [
            'user' => $user,
            'token' => $this->issueToken($user),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('agentProfile');

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
        ]);

        return $plainToken;
    }
}
