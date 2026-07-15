<?php

namespace Tests\Feature;

use App\Data\HermesSemanticInterpretation;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\AssistantRunService;
use App\Services\BrowserVoiceInvariantAuditService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BrowserVoiceInvariantAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_audit_passes_for_consistent_semantic_turns_in_human_and_json_modes(): void
    {
        $token = $this->apiToken('voice-invariant-audit-pass@example.com');
        $sessionId = $this->sessionId($token);
        $clock = $this->admit($token, $sessionId, 'audit-pass-clock-0001', 'What time is it?');
        $summary = $this->admit(
            $token,
            $sessionId,
            'audit-pass-summary-0002',
            'Summarize what I asked.',
        );
        $this->completeSemanticResponse($clock, 'It is 2:00 p.m.');
        $this->completeSemanticResponse($summary, 'You asked for the time and then a summary.');

        $directReport = app(BrowserVoiceInvariantAuditService::class)->audit(1);
        $this->assertSame('pass', $directReport['status'], json_encode($directReport, JSON_PRETTY_PRINT));

        $this->artisan('browser-voice:audit-invariants', ['--chunk' => 1])
            ->expectsOutputToContain('Browser Voice v2 invariant audit: PASS')
            ->expectsOutputToContain('Violations: 0')
            ->assertSuccessful();

        $exit = Artisan::call('browser-voice:audit-invariants', ['--json' => true, '--chunk' => 1]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $report['status']);
        $this->assertSame(2, $report['counts']['turns']);
        $this->assertSame(4, $report['counts']['messages']);
        $this->assertSame(2, $report['counts']['runs']);
        $this->assertGreaterThan(0, $report['counts']['events']);
        $this->assertSame(0, $report['counts']['violations']);
        $this->assertSame([], $report['violations_by_code']);
        $this->assertSame([], $report['violation_samples']);
        $this->assertFalse($report['samples_truncated']);
    }

    public function test_json_audit_reports_each_durable_invariant_without_mutating_rows(): void
    {
        $token = $this->apiToken('voice-invariant-audit-fail@example.com');
        $sessionId = $this->sessionId($token);
        $missingMessages = $this->admit(
            $token,
            $sessionId,
            'audit-bad-messages-0001',
            'What time is it?',
        );
        $this->completeSemanticResponse($missingMessages, 'It is 2:00 p.m.');
        $missingMessages->refresh();
        $missingMessages->userMessage()->delete();
        $missingMessages->finalAssistantMessage()->delete();

        $active = $this->admit(
            $token,
            $sessionId,
            'audit-bad-active-0002',
            'Create a detailed seven-day travel plan.',
        );
        DB::table('voice_turns')->where('id', $active->id)->update([
            'hard_deadline_at' => now()->subSecond(),
            'no_progress_deadline_at' => now()->subSecond(),
            'metadata' => json_encode(['nested' => ['raw_audio' => 'private microphone bytes']], JSON_THROW_ON_ERROR),
        ]);
        $active->refresh();
        ConversationMessage::create([
            'user_id' => $active->user_id,
            'conversation_session_id' => $sessionId,
            'client_turn_id' => $active->turn_id,
            'role' => 'assistant',
            'content' => 'Premature final.',
            'metadata' => ['source' => 'browser_voice_v2'],
        ]);
        $run = $active->runs()->firstOrFail();
        DB::table('assistant_runs')->where('id', $run->id)->update([
            'lane' => null,
            'handler' => null,
        ]);
        $run->update([
            'status' => 'completed',
            'completed_at' => null,
            'metadata' => ['required' => true, 'pcm_data' => 'private bytes'],
            'result' => ['status' => 'failed'],
        ]);
        VoiceTurnEvent::create([
            'voice_turn_id' => $active->id,
            'user_id' => $active->user_id,
            'workspace_id' => $active->workspace_id,
            'conversation_session_id' => $sessionId,
            'sequence' => ((int) $active->events()->max('sequence')) + 1,
            'event_type' => 'corrupt_test_event',
            'from_state' => 'accepted',
            'to_state' => 'accepted',
            'version' => $active->version,
            'source' => 'test',
            'payload' => ['nested' => ['audio_blob' => 'private bytes']],
        ]);

        $canceled = $this->admit(
            $token,
            $sessionId,
            'audit-bad-canceled-0003',
            'Create a detailed five-day packing plan.',
        );
        app(VoiceTurnLifecycleService::class)->cancel($canceled);
        $canceled->fresh()->update(['terminal_at' => null]);
        ConversationMessage::create([
            'user_id' => $canceled->user_id,
            'conversation_session_id' => $sessionId,
            'client_turn_id' => $canceled->turn_id,
            'role' => 'assistant',
            'content' => 'Canceled work must not have this final.',
            'metadata' => ['source' => 'browser_voice_v2'],
        ]);

        $before = $this->databaseSnapshot();
        $exit = Artisan::call('browser-voice:audit-invariants', ['--json' => true, '--chunk' => 1]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $after = $this->databaseSnapshot();

        $this->assertSame(1, $exit);
        $this->assertSame('fail', $report['status']);
        foreach ([
            'user_message_count',
            'final_message_count',
            'nonterminal_turn_has_final',
            'terminal_timestamp_missing',
            'active_hard_deadline_exceeded',
            'active_no_progress_deadline_exceeded',
            'required_run_barrier_not_finalized',
            'voice_run_lane_missing_or_invalid',
            'voice_run_handler_missing',
            'run_terminal_timestamp_missing',
            'run_result_status_mismatch',
            'canceled_turn_has_final',
            'raw_audio_key_in_turn_metadata',
            'raw_audio_key_in_event_metadata',
            'raw_audio_key_in_run_metadata',
        ] as $code) {
            $this->assertArrayHasKey($code, $report['violations_by_code']);
            $this->assertGreaterThan(0, $report['violations_by_code'][$code]);
        }
        $this->assertSame($before, $after, 'The invariant audit must be strictly read-only.');
    }

    public function test_voice_run_creation_requires_explicit_lane_and_handler(): void
    {
        $token = $this->apiToken('voice-run-routing-invariant@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit($token, $sessionId, 'audit-routing-0001', 'What time is it?');
        $base = [
            'voice_turn_id' => $turn->id,
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'conversation_session_id' => $turn->conversation_session_id,
            'source' => 'browser_voice_v2',
            'status' => 'queued',
        ];

        try {
            AssistantRun::create([
                ...$base,
                'handler' => 'agent.semantic',
                'idempotency_key' => $turn->turn_id.':missing-lane',
            ]);
            $this->fail('A voice run without a lane must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Browser voice runs require an explicit valid lane.', $exception->getMessage());
        }

        try {
            AssistantRun::create([
                ...$base,
                'lane' => 'semantic',
                'idempotency_key' => $turn->turn_id.':missing-handler',
            ]);
            $this->fail('A voice run without a handler must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Browser voice runs require an explicit handler.', $exception->getMessage());
        }

        $this->assertSame(1, $turn->runs()->count());
    }

    private function completeSemanticResponse(VoiceTurn $turn, string $response): void
    {
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->once()->andReturn(new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
            responseText: $response,
            clarificationQuestion: null,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: false,
            operations: [],
        ));
        $interpreter->shouldNotReceive('compose');
        $run = $turn->runs()->sole();
        (new ProcessAssistantRun($run->id))->handle(
            runtime: app(HermesRuntimeService::class),
            runs: app(AssistantRunService::class),
            voiceTurns: app(VoiceTurnLifecycleService::class),
            semanticInterpreter: $interpreter,
        );
    }

    private function admit(string $token, int $sessionId, string $turnId, string $transcript): VoiceTurn
    {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'client_context' => [
                'voice_mode_active' => true,
                'wake_detection_enabled' => true,
                'playback_state' => 'idle',
            ],
        ])->assertCreated();

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    private function sessionId(string $token): int
    {
        return (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
    }

    /** @return array<string, string> */
    private function databaseSnapshot(): array
    {
        return collect(['voice_turns', 'voice_turn_events', 'assistant_runs', 'conversation_messages'])
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->orderBy('id')->get()->toJson(),
            ])
            ->all();
    }
}
