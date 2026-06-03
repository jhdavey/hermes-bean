<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\EarlyAccessSignup;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Tests\TestCase;

class AuthAndAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_records_early_access_signup(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Bean User',
            'email' => 'bean@example.com',
            'plan' => 'pro',
        ])->assertCreated()
            ->assertJsonPath('data.message', "You're on the early access list. We'll email you as soon as we can give you access.")
            ->assertJsonPath('data.early_access_signup.email', 'bean@example.com')
            ->assertJsonPath('data.early_access_signup.name', 'Bean User')
            ->assertJsonPath('data.early_access_signup.source', 'pricing_register')
            ->assertJsonPath('data.early_access_signup.requested_plan', 'pro');

        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'bean@example.com',
            'name' => 'Bean User',
            'requested_plan' => 'pro',
            'source' => 'pricing_register',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'bean@example.com']);
    }

    public function test_converted_early_access_user_keeps_early_access_flag_while_signup_record_exists(): void
    {
        EarlyAccessSignup::create([
            'name' => 'Converted User',
            'email' => 'converted@example.com',
            'source' => 'app_register',
        ]);
        $token = $this->apiToken('converted@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_early_access', true)
            ->assertJsonPath('data.early_access_signup.email', 'converted@example.com')
            ->assertJsonPath('data.early_access_signup.source', 'app_register');
    }

    public function test_regular_user_without_early_access_signup_is_not_marked_early_access(): void
    {
        $token = $this->apiToken('regular@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_early_access', false)
            ->assertJsonPath('data.early_access_signup', null);
    }

    public function test_login_me_logout_and_hashed_tokens(): void
    {
        User::factory()->create([
            'name' => 'Bean User',
            'email' => 'bean@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'bean@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Bean User');

        $plainToken = $login->json('data.token');
        $userId = User::where('email', 'bean@example.com')->value('id');
        $hashedRegisterToken = hash('sha256', $plainToken);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plainToken]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'user_id' => $userId,
            'token' => $hashedRegisterToken,
        ]);
        $registerTokenRow = PersonalAccessToken::where('token', $hashedRegisterToken)->firstOrFail();
        $this->assertNotNull($registerTokenRow->expires_at);
        $this->assertTrue($registerTokenRow->expires_at->isFuture());

        $this->withToken($plainToken)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'bean@example.com');

        $this->withToken($plainToken)->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->withToken($plainToken)->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_signed_in_user_can_update_email(): void
    {
        $token = $this->registerToken('editable@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'email' => 'updated@example.com',
        ])->assertOk()
            ->assertJsonPath('data.email', 'updated@example.com');

        $this->assertDatabaseHas('users', ['email' => 'updated@example.com']);

        $this->withToken($token)->patchJson('/api/auth/me', [
            'email' => 'not-an-email',
        ])->assertUnprocessable();
    }

    public function test_signed_in_user_can_update_theme(): void
    {
        $token = $this->registerToken('theme-user@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.theme', 'green');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'theme' => 'purple',
        ])->assertOk()
            ->assertJsonPath('data.theme', 'purple');

        $this->assertDatabaseHas('users', [
            'email' => 'theme-user@example.com',
            'theme' => 'purple',
        ]);

        $this->withToken($token)->patchJson('/api/auth/me', [
            'theme' => 'neon',
        ])->assertUnprocessable();
    }

    public function test_forgot_password_sends_reset_link_without_revealing_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'bean@example.com']);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'BEAN@example.com ',
        ])->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, we sent a password reset link.');

        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user): bool {
            return str_contains($notification->resetUrl($user), '/reset-password')
                && str_contains($notification->resetUrl($user), 'email=bean%40example.com');
        });

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, we sent a password reset link.');
    }

    public function test_web_password_reset_changes_password_and_returns_to_app_login(): void
    {
        $user = User::factory()->create([
            'email' => 'bean@example.com',
            'password' => Hash::make('old-password-12345'),
        ]);
        $token = PasswordBroker::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'bean@example.com',
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertOk()
            ->assertSee('Your password has been reset')
            ->assertSee('Back to app login');

        $this->postJson('/api/auth/login', [
            'email' => 'bean@example.com',
            'password' => 'new-password-12345',
        ])->assertOk();
    }

    public function test_new_user_starts_with_empty_user_resources(): void
    {
        $token = $this->registerToken('clean@example.com');

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.counts.tasks', 0)
            ->assertJsonPath('data.counts.reminders', 0)
            ->assertJsonPath('data.counts.calendar_events', 0);

        $this->withToken($token)->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('conversation_sessions', [
            'user_id' => User::where('email', 'clean@example.com')->value('id'),
            'title' => 'Welcome to Bean',
            'runtime_mode' => 'onboarding',
        ]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_login_backfills_welcome_conversation_without_resources(): void
    {
        $user = User::factory()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'existing@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('conversation_sessions', [
            'user_id' => $user->id,
            'title' => 'Welcome to Bean',
            'runtime_mode' => 'onboarding',
        ]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_assistant_routes_require_auth_and_scope_route_model_binding_to_owner(): void
    {
        $aliceToken = $this->registerToken('alice@example.com');
        $bobToken = $this->registerToken('bob@example.com');

        $this->postJson('/api/assistant/sessions', ['title' => 'No auth'])
            ->assertUnauthorized();

        $sessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', [
            'title' => 'Alice planning',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', User::where('email', 'alice@example.com')->value('id'))
            ->json('data.id');

        $this->withToken($bobToken)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertNotFound();

        $this->withToken($bobToken)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Try to write into Alice session',
        ])->assertNotFound();

        $this->configureTaskCreatingAgent();

        $this->withToken($aliceToken)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add task Follow up with Sam.',
        ])->assertCreated();

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'user_id' => User::where('email', 'alice@example.com')->value('id'),
            'role' => 'user',
        ]);

        $this->assertDatabaseHas('tasks', [
            'conversation_session_id' => $sessionId,
            'user_id' => User::where('email', 'alice@example.com')->value('id'),
            'title' => 'Follow up with Sam',
        ]);
    }

    public function test_domain_resources_are_owned_and_cannot_attach_to_another_users_session(): void
    {
        $aliceToken = $this->registerToken('alice@example.com');
        $bobToken = $this->registerToken('bob@example.com');

        $aliceSessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', [
            'title' => 'Alice',
        ])->assertCreated()->json('data.id');

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'conversation_session_id' => $aliceSessionId,
            'title' => 'Cross account task',
            'type' => 'todo',
        ])->assertUnprocessable();

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'title' => 'Bob task',
            'type' => 'todo',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', User::where('email', 'bob@example.com')->value('id'));
    }

    public function test_export_returns_only_owned_assistant_data_and_delete_removes_account_data_and_tokens(): void
    {
        $aliceToken = $this->registerToken('alice@example.com');
        $bobToken = $this->registerToken('bob@example.com');

        $aliceSessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', ['title' => 'Alice export'])
            ->assertCreated()->json('data.id');
        $this->configureTaskCreatingAgent();
        $this->withToken($aliceToken)->postJson("/api/assistant/sessions/{$aliceSessionId}/messages", [
            'content' => 'Add task Export Alice task.',
        ])->assertCreated();

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'title' => 'Bob private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($aliceToken)->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonFragment(['title' => 'Export Alice task'])
            ->assertJsonMissing(['title' => 'Bob private task']);

        $aliceId = User::where('email', 'alice@example.com')->value('id');
        $alicePersonalWorkspaceId = Workspace::where('personal_owner_user_id', $aliceId)->value('id');

        $this->withToken($aliceToken)->deleteJson('/api/account')
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $aliceId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('workspaces', ['id' => $alicePersonalWorkspaceId]);
        $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $alicePersonalWorkspaceId]);
        $this->assertDatabaseMissing('conversation_sessions', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('tasks', ['user_id' => $aliceId]);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
        $this->assertDatabaseHas('tasks', ['title' => 'Bob private task']);
    }

    private function configureTaskCreatingAgent(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');

        Http::fake(function ($request) {
            $payload = $request->data();
            $hasToolResult = collect($payload['messages'] ?? [])->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool');
            if ($hasToolResult) {
                return Http::response([
                    'id' => 'chatcmpl-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => ['role' => 'assistant', 'content' => 'Created the task.'],
                    ]],
                ]);
            }

            $content = (string) data_get($payload, 'messages.2.content', '');
            $title = trim((string) preg_replace('/^Add task\s+/i', '', $content), " .\t\n\r\0\x0B");

            return Http::response([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_task',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode(['title' => $title, 'type' => 'todo'], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ]);
        });
    }

    private function registerToken(string $email): string
    {
        return $this->apiToken($email);
    }
}
