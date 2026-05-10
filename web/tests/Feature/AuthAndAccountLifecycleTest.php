<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_login_me_logout_and_hashed_tokens(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Bean User',
            'email' => 'bean@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'bean@example.com');

        $plainToken = $register->json('data.token');
        $this->assertIsString($plainToken);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plainToken]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'user_id' => User::where('email', 'bean@example.com')->value('id'),
            'token' => hash('sha256', $plainToken),
        ]);

        $this->withToken($plainToken)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'bean@example.com');

        $loginToken = $this->postJson('/api/auth/login', [
            'email' => 'bean@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Bean User')
            ->json('data.token');

        $this->withToken($loginToken)->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->withToken($loginToken)->getJson('/api/auth/me')
            ->assertUnauthorized();
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

        $this->withToken($aliceToken)->deleteJson('/api/account')
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $aliceId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('conversation_sessions', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('tasks', ['user_id' => $aliceId]);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
        $this->assertDatabaseHas('tasks', ['title' => 'Bob private task']);
    }

    private function registerToken(string $email): string
    {
        return $this->postJson('/api/auth/register', [
            'name' => str($email)->before('@')->title()->toString(),
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()->json('data.token');
    }
}
