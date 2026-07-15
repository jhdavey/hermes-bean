<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Services\BrowserVoiceInvariantAuditService;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeVoiceInvariantAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_transcript_free_abandoned_admission_passes_the_read_only_invariant_audit(): void
    {
        $this->apiToken('realtime-audit@example.com');
        $user = User::query()->where('email', 'realtime-audit@example.com')->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessions = app(RealtimeVoiceSessionService::class);
        $realtime = $sessions->createPending($user, $conversation, 'gpt-realtime-test', 'alloy', 1);
        $realtime = $sessions->bindProviderCall($realtime, 'call_audit_1');
        $leased = $sessions->acquireLease($realtime, 'audit-daemon', 30);
        $this->assertNotNull($leased);
        $realtime = $sessions->markReady($leased, 'audit-daemon');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = $lifecycle->preAdmitRealtime($user, $conversation, $realtime, [
            'turn_id' => 'realtime-audit-turn-0001',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'input_generation' => 0,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
        ]);
        $lifecycle->abandonPendingRealtimeInput($turn, 'test_abandonment');

        $report = app(BrowserVoiceInvariantAuditService::class)->audit(1);
        $this->assertSame('pass', $report['status'], json_encode($report, JSON_PRETTY_PRINT));

        $exit = Artisan::call('voice:audit-realtime-invariants', ['--json' => true, '--chunk' => 1]);
        $commandReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('pass', $commandReport['status']);
        $this->assertSame(1, $commandReport['counts']['turns']);
        $this->assertSame(1, $commandReport['counts']['messages']);
        $this->assertSame(0, $commandReport['counts']['runs']);
        $this->assertSame(0, $commandReport['counts']['violations']);
    }
}
