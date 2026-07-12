<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\BrowserVoiceInvariantAuditService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function test_audit_passes_for_consistent_rows_in_human_and_json_modes(): void
    {
        $token = $this->apiToken('voice-invariant-audit-pass@example.com');
        $sessionId = $this->sessionId($token);
        $this->admit($token, $sessionId, 'audit-pass-instant-0001', 'What time is it?');
        $complex = $this->admit(
            $token,
            $sessionId,
            'audit-pass-complex-0001',
            'Create a detailed three-day meal plan and save it as a note.',
        );
        app(VoiceTurnLifecycleService::class)->finishJob(
            $complex->runs()->firstOrFail(),
            'completed',
            'I created the three-day meal plan note.',
        );
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
        $this->assertSame(1, $report['counts']['runs']);
        $this->assertGreaterThan(0, $report['counts']['events']);
        $this->assertSame(0, $report['counts']['violations']);
        $this->assertSame([], $report['violations_by_code']);
        $this->assertSame([], $report['violation_samples']);
        $this->assertFalse($report['samples_truncated']);
    }

    public function test_json_audit_fails_on_each_release_invariant_and_does_not_mutate_rows(): void
    {
        $token = $this->apiToken('voice-invariant-audit-fail@example.com');
        $sessionId = $this->sessionId($token);
        $instant = $this->admit($token, $sessionId, 'audit-bad-instant-0001', 'What time is it?');
        $instant->userMessage()->delete();
        $instant->finalAssistantMessage()->delete();

        $active = $this->admit(
            $token,
            $sessionId,
            'audit-bad-active-0001',
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
            'audit-bad-canceled-0001',
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

    private function admit(string $token, int $sessionId, string $turnId, string $transcript): VoiceTurn
    {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
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
