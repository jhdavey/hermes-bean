<?php

namespace Tests\Feature;

use App\Models\EarlyAccessSignup;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ResetPasswordLink;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthAndAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_auth_token_and_early_access_signup(): void
    {
        Notification::fake();
        $usersPath = storage_path('framework/testing/hermes-register-'.uniqid());
        config(['bean.hermes.users_path' => $usersPath]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bean User',
            'email' => 'bean@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'plan' => 'pro',
            'theme_mode' => 'light',
        ])->assertCreated()
            ->assertJsonPath('data.user.email', 'bean@example.com')
            ->assertJsonPath('data.user.subscription_tier', 'base')
            ->assertJsonPath('data.user.theme_mode', 'light')
            ->assertJsonPath('data.user.is_early_access', true)
            ->assertJsonPath('data.selected_plan', 'pro');

        $this->assertIsString($response->json('data.token'));
        $this->assertDatabaseHas('users', [
            'email' => 'bean@example.com',
            'name' => 'Bean User',
            'subscription_tier' => 'base',
            'theme_mode' => 'light',
            'onboard_complete' => true,
        ]);
        $this->assertDatabaseHas('workspaces', [
            'name' => 'Bean User Personal Workspace',
            'type' => 'personal',
        ]);
        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'bean@example.com',
            'name' => 'Bean User',
            'requested_plan' => 'pro',
            'source' => 'app_register',
        ]);

        $user = User::where('email', 'bean@example.com')->firstOrFail();
        $home = $usersPath.'/'.$user->id;
        $this->assertDirectoryExists($home);
        $this->assertFileExists($home.'/config.yaml');
        $this->assertFileExists($home.'/skills/bean-dashboard/SKILL.md');
        $this->assertFileExists($home.'/plugins/bean-dashboard/plugin.yaml');
        $this->assertStringContainsString('bean_dashboard', File::get($home.'/config.yaml'));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_verification_link_marks_email_verified(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Verify User',
            'email' => 'verify@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()
            ->assertJsonPath('data.user.email_verified', false);

        $user = User::where('email', 'verify@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($verificationUrl)->assertRedirect('/login?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_availability_reports_taken_and_available_addresses(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/email-availability', [
            'email' => ' Taken@Example.com ',
        ])->assertOk()
            ->assertJsonPath('data.email', 'taken@example.com')
            ->assertJsonPath('data.available', false);

        $this->postJson('/api/auth/email-availability', [
            'email' => 'fresh@example.com',
        ])->assertOk()
            ->assertJsonPath('data.email', 'fresh@example.com')
            ->assertJsonPath('data.available', true);
    }

    public function test_converted_early_access_user_keeps_early_access_flag_while_signup_record_exists(): void
    {
        EarlyAccessSignup::create([
            'name' => 'Converted User',
            'email' => 'converted@example.com',
            'source' => 'app_register',
            'status' => 'admitted',
            'admitted_at' => now(),
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
        $usersPath = storage_path('framework/testing/hermes-login-'.uniqid());
        config(['bean.hermes.users_path' => $usersPath]);
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
        $this->assertDirectoryExists($usersPath.'/'.$userId);
        $this->assertFileExists($usersPath.'/'.$userId.'/config.yaml');
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
            ->assertJsonPath('data.theme', 'green')
            ->assertJsonPath('data.theme_mode', 'auto');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'theme' => 'purple',
            'theme_mode' => 'dark',
        ])->assertOk()
            ->assertJsonPath('data.theme', 'purple')
            ->assertJsonPath('data.theme_mode', 'dark');

        $this->assertDatabaseHas('users', [
            'email' => 'theme-user@example.com',
            'theme' => 'purple',
            'theme_mode' => 'dark',
        ]);

        $this->withToken($token)->patchJson('/api/auth/me', [
            'theme' => 'neon',
        ])->assertUnprocessable();

        $this->withToken($token)->patchJson('/api/auth/me', [
            'theme_mode' => 'sepia',
        ])->assertUnprocessable();
    }

    public function test_signed_in_user_can_update_command_center_label(): void
    {
        $token = $this->registerToken('command-center-user@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.command_center_label', 'Command Center');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'command_center_label' => 'Mission Control',
        ])->assertOk()
            ->assertJsonPath('data.command_center_label', 'Mission Control');

        $this->assertDatabaseHas('users', [
            'email' => 'command-center-user@example.com',
            'command_center_label' => 'Mission Control',
        ]);

        $this->withToken($token)->patchJson('/api/auth/me', [
            'command_center_label' => '',
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
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Clean User',
            'email' => 'clean@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/today')
            ->assertPaymentRequired()
            ->assertJsonPath('code', 'subscription_required');

        User::where('email', 'clean@example.com')->firstOrFail()
            ->forceFill(['subscription_status' => 'trialing'])
            ->save();

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.counts.tasks', 0)
            ->assertJsonPath('data.counts.reminders', 0)
            ->assertJsonPath('data.counts.calendar_events', 0);

        $this->withToken($token)->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_export_returns_only_owned_productivity_data_and_delete_removes_account_data_and_tokens(): void
    {
        $usersPath = storage_path('framework/testing/hermes-delete-'.uniqid());
        config(['bean.hermes.users_path' => $usersPath]);
        $aliceToken = $this->registerToken('alice@example.com');
        $bobToken = $this->registerToken('bob@example.com');

        $this->withToken($aliceToken)->postJson('/api/tasks', [
            'title' => 'Export Alice task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'title' => 'Bob private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($aliceToken)->putJson('/api/daily-sticky-note', [
            'date' => '2026-07-20',
            'content' => 'Alice private sticky note',
        ])->assertOk();

        $this->withToken($bobToken)->putJson('/api/daily-sticky-note', [
            'date' => '2026-07-20',
            'content' => 'Bob private sticky note',
        ])->assertOk();

        $this->withToken($aliceToken)->getJson('/api/account/export')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonFragment(['title' => 'Export Alice task'])
            ->assertJsonFragment(['content' => 'Alice private sticky note'])
            ->assertJsonMissing(['title' => 'Bob private task'])
            ->assertJsonMissing(['content' => 'Bob private sticky note']);

        $aliceId = User::where('email', 'alice@example.com')->value('id');
        $alicePersonalWorkspaceId = Workspace::where('personal_owner_user_id', $aliceId)->value('id');
        File::ensureDirectoryExists($usersPath.'/'.$aliceId.'/sessions');
        File::put($usersPath.'/'.$aliceId.'/config.yaml', 'test hermes home');
        $this->assertDirectoryExists($usersPath.'/'.$aliceId);

        $this->withToken($aliceToken)->deleteJson('/api/account')
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $aliceId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('workspaces', ['id' => $alicePersonalWorkspaceId]);
        $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $alicePersonalWorkspaceId]);
        $this->assertDatabaseMissing('tasks', ['user_id' => $aliceId]);
        $this->assertDatabaseMissing('daily_sticky_notes', ['user_id' => $aliceId]);
        $this->assertDirectoryDoesNotExist($usersPath.'/'.$aliceId);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
        $this->assertDatabaseHas('tasks', ['title' => 'Bob private task']);
        $this->assertDatabaseHas('daily_sticky_notes', ['content' => 'Bob private sticky note']);
    }

    private function registerToken(string $email): string
    {
        return $this->apiToken($email);
    }
}
