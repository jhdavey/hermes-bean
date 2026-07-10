<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\VoiceTurnAbandonmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceTurnAbandonmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_accepted_turn_is_abandoned_without_assistant_text_and_late_completion_cannot_replace_it(): void
    {
        config()->set('services.openai.realtime_turn_abandon_after_seconds', 60);
        $this->travelTo(CarbonImmutable::parse('2026-07-10 12:00:00 UTC'));
        $token = $this->apiToken('voice-abandonment@example.com');
        $user = User::where('email', 'voice-abandonment@example.com')->firstOrFail();
        $user->forceFill(['is_admin' => true])->save();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $payload = [
            'session_id' => $sessionId,
            'user_text' => 'Tell me something brief.',
            'metadata' => [
                'client_turn_id' => 'realtime-abandoned-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'direct',
                ],
            ],
        ];

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$payload,
            'outcome' => 'accepted',
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'accepted')
            ->assertJsonPath('data.assistant_message', null);

        $this->travel(61)->seconds();
        // If the scheduler is delayed, the report does not mislabel an overdue
        // acceptance as genuinely in flight.
        $this->withToken($token)->getJson('/api/admin/voice-quality?days=1')
            ->assertOk()
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.accepted_non_terminal_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.in_flight_count', 0)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.stale_accepted_unreconciled_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.abandoned_count', 0);

        $result = app(VoiceTurnAbandonmentService::class)->reconcile();

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(1, $result['abandoned_count']);
        $message = ConversationMessage::query()
            ->where('conversation_session_id', $sessionId)
            ->where('client_turn_id', 'realtime-abandoned-1')
            ->where('role', 'user')
            ->firstOrFail();
        $this->assertSame('abandoned', data_get($message->metadata, 'voice_turn_outcome.status'));
        $this->assertSame(
            VoiceTurnAbandonmentService::REASON,
            data_get($message->metadata, 'voice_turn_outcome.reason'),
        );
        $this->assertSame(
            'server_stale_accepted_turn_reconciler',
            data_get($message->metadata, 'voice_turn_outcome.classified_by'),
        );
        $this->assertSame(60, data_get($message->metadata, 'voice_turn_outcome.abandon_after_seconds'));
        $this->assertNotNull(data_get($message->metadata, 'voice_turn_outcome.accepted_at'));
        $this->assertNotNull(data_get($message->metadata, 'voice_turn_outcome.terminal_at'));
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'client_turn_id' => 'realtime-abandoned-1',
            'role' => 'assistant',
        ]);

        // Reconciliation is idempotent and a late provider completion cannot overwrite
        // the server's first terminal classification or fabricate durable assistant text.
        $this->assertSame(0, app(VoiceTurnAbandonmentService::class)->reconcile()['abandoned_count']);
        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$payload,
            'outcome' => 'completed',
            'assistant_text' => 'This response arrived after the browser disappeared.',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'abandoned')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.status', 'abandoned')
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.reason', VoiceTurnAbandonmentService::REASON);
        $this->assertDatabaseCount('conversation_messages', 1);

        $this->withToken($token)->getJson('/api/admin/voice-quality?days=1')
            ->assertOk()
            ->assertJsonPath('data.population.route_outcome_counts.direct.abandoned', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.terminal_outcome_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.terminal_coverage_percent', 100)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.abandoned_count', 1)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.in_flight_count', 0)
            ->assertJsonPath('data.outcome_coverage.direct_and_status_voice_turns.stale_accepted_unreconciled_count', 0)
            ->assertJsonPath('data.coverage.lifecycle_reconciliation.abandon_after_seconds', 60);
    }

    public function test_fresh_acceptance_and_existing_terminal_outcome_are_never_reclassified(): void
    {
        config()->set('services.openai.realtime_turn_abandon_after_seconds', 60);
        $this->travelTo(CarbonImmutable::parse('2026-07-10 13:00:00 UTC'));
        $token = $this->apiToken('voice-abandonment-guard@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $payload = [
            'session_id' => $sessionId,
            'user_text' => 'Give me a status update.',
            'metadata' => [
                'client_turn_id' => 'realtime-terminal-wins-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'status',
                ],
            ],
        ];

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$payload,
            'outcome' => 'accepted',
        ])->assertCreated();
        $this->travel(59)->seconds();
        $this->assertSame(0, app(VoiceTurnAbandonmentService::class)->reconcile()['abandoned_count']);

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$payload,
            'outcome' => 'cancelled',
            'failure_reason' => 'client_disconnected',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'cancelled');

        $this->travel(2)->minutes();
        $this->artisan('voice-turns:reconcile-abandoned')
            ->expectsOutputToContain('Reconciled 0 stale accepted voice turn(s)')
            ->assertSuccessful();

        $message = ConversationMessage::query()
            ->where('client_turn_id', 'realtime-terminal-wins-1')
            ->where('role', 'user')
            ->firstOrFail();
        $this->assertSame('cancelled', data_get($message->metadata, 'voice_turn_outcome.status'));
        $this->assertSame('client_disconnected', data_get($message->metadata, 'voice_turn_outcome.reason'));
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'client_turn_id' => 'realtime-terminal-wins-1',
            'role' => 'assistant',
        ]);
    }

    public function test_scheduled_command_reconciles_stale_status_route_turns(): void
    {
        config()->set('services.openai.realtime_turn_abandon_after_seconds', 60);
        $this->travelTo(CarbonImmutable::parse('2026-07-10 14:00:00 UTC'));
        $token = $this->apiToken('voice-status-abandonment@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            'session_id' => $sessionId,
            'user_text' => 'Are you still working on that?',
            'outcome' => 'accepted',
            'metadata' => [
                'client_turn_id' => 'realtime-status-abandoned-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'status',
                ],
            ],
        ])->assertCreated();

        $this->travel(61)->seconds();
        $this->artisan('voice-turns:reconcile-abandoned')
            ->expectsOutputToContain('Reconciled 1 stale accepted voice turn(s)')
            ->assertSuccessful();

        $message = ConversationMessage::query()
            ->where('client_turn_id', 'realtime-status-abandoned-1')
            ->where('role', 'user')
            ->firstOrFail();
        $this->assertSame('status', data_get($message->metadata, 'voice_quality.route'));
        $this->assertSame('abandoned', data_get($message->metadata, 'voice_turn_outcome.status'));
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'client_turn_id' => 'realtime-status-abandoned-1',
            'role' => 'assistant',
        ]);
    }
}
