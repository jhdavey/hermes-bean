<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserVoiceV2ConversationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_strict_wake_cannot_use_a_stale_pronoun_to_delete_the_only_old_reminder(): void
    {
        [$token, $session] = $this->voiceSession('context-strict@example.com');
        $reminder = $this->reminder($session, 'Keep this old reminder');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            'context-strict-read-0001',
            'What reminders do I have?',
            'new_conversation',
            1,
        ))->assertCreated()
            ->assertJsonPath('data.turn.state', 'accepted');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            'context-strict-delete-0002',
            'Delete that reminder.',
            'new_conversation',
            2,
        ))->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'Which reminder should I change?');

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'context-strict-delete-0002']);

        // A continuation claim with no matching durable epoch anchor also
        // fails closed; the server does not trust the mode flag by itself.
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            'context-unanchored-delete-0003',
            'Delete that reminder.',
            'contextual_follow_up',
            99,
        ))->assertUnprocessable()
            ->assertJsonPath('question', 'Which reminder should I change?');
        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
    }

    public function test_same_epoch_follow_up_uses_the_prior_typed_context_and_deletes_once(): void
    {
        [$token, $session] = $this->voiceSession('context-follow-up@example.com');
        $reminder = $this->reminder($session, 'Follow-up target');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            'context-follow-up-read-0001',
            'What reminders do I have?',
            'new_conversation',
            7,
        ))->assertCreated();

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            'context-follow-up-delete-0002',
            'Delete that reminder.',
            'contextual_follow_up',
            7,
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.reminder.delete');

        $turn = VoiceTurn::where('turn_id', 'context-follow-up-delete-0002')->firstOrFail();
        $this->assertTrue((bool) data_get($turn->metadata, 'prior_context_authorized'));
        $this->assertSame('app.reminder.read', data_get($turn->metadata, 'prior_handler'));

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(1, $turn->fresh()->runs()->where('status', 'completed')->count());
    }

    public function test_admission_context_is_part_of_the_stable_turn_fingerprint(): void
    {
        [$token, $session] = $this->voiceSession('context-fingerprint@example.com');
        $payload = $this->payload(
            $session,
            'context-fingerprint-0001',
            'What time is it?',
            'new_conversation',
            3,
        );

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$payload,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 4],
        ])->assertConflict();

        $this->assertSame(1, VoiceTurn::where('turn_id', 'context-fingerprint-0001')->count());
        $this->assertSame(3, data_get(
            VoiceTurn::where('turn_id', 'context-fingerprint-0001')->firstOrFail()->metadata,
            'conversation_context.epoch',
        ));
    }

    /** @return array{string, ConversationSession} */
    private function voiceSession(string $email): array
    {
        $token = $this->apiToken($email);
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        return [$token, ConversationSession::findOrFail($sessionId)];
    }

    private function reminder(ConversationSession $session, string $title): Reminder
    {
        return Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => $title,
            'remind_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(
        ConversationSession $session,
        string $turnId,
        string $transcript,
        string $mode,
        int $epoch,
    ): array {
        return [
            'turn_id' => $turnId,
            'session_id' => $session->id,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'conversation_context' => ['mode' => $mode, 'epoch' => $epoch],
        ];
    }
}
