<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationSessionTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_session_lookup_requires_explicit_timezone_context(): void
    {
        Carbon::setTestNow('2026-07-14T12:00:00Z');
        $token = $this->apiToken('daily-session-timezone@example.com');

        $this->withToken($token)->getJson('/api/assistant/sessions?date=2026-07-14')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');

        $this->withToken($token)->getJson('/api/assistant/sessions?timezone=-04:00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_daily_session_lookup_accepts_an_explicit_utc_offset_without_using_server_timezone(): void
    {
        Carbon::setTestNow('2026-07-14T12:00:00Z');
        $token = $this->apiToken('daily-session-offset@example.com');
        $user = User::query()->where('email', 'daily-session-offset@example.com')->firstOrFail();

        $session = ConversationSession::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'status' => 'active',
        ]);
        DB::table('conversation_sessions')->where('id', $session->id)->update([
            'created_at' => '2026-07-15 02:30:00',
            'updated_at' => '2026-07-15 02:30:00',
        ]);

        $this->withToken($token)->getJson('/api/assistant/sessions?date=2026-07-14&timezone=-04%3A00')
            ->assertOk()
            ->assertJsonPath('data.today_session.id', $session->id);

        $this->withToken($token)->getJson('/api/assistant/sessions?date=2026-07-15&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.today_session.id', $session->id);
    }
}
