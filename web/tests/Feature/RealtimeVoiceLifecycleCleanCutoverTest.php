<?php

namespace Tests\Feature;

use App\Enums\VoiceRealtimeSessionStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RealtimeVoiceLifecycleCleanCutoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_audio_native_pre_admission_is_hidden_idempotent_and_has_no_transcript_or_job(): void
    {
        $fixture = $this->fixture('clean-admission@example.com');
        $input = $this->admissionInput('clean-audio-native-turn-0001');
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $turn = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $input,
        );
        $duplicate = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $input,
        );

        $this->assertSame($turn->id, $duplicate->id);
        $this->assertSame('browser_voice_realtime', $turn->source);
        $this->assertSame('voice_only', $turn->display_mode);
        $this->assertNull($turn->semantic_input);
        $this->assertFalse(Schema::hasColumn('voice_turns', 'transcript'));
        $this->assertFalse(Schema::hasColumn('voice_turns', 'sanitized_transcript'));
        $this->assertFalse(Schema::hasColumn('voice_turns', 'final_delivered_at'));
        $this->assertSame(0, $turn->runs()->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $fixture['conversation']->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->where('origin', 'spoken_voice')
            ->where('display_mode', 'voice_only')
            ->where('content', '')
            ->count());
        $this->assertSame(0, $fixture['conversation']->messages()->visibleInChat()->count());
        $this->assertSame(1, $turn->events()->where('event_type', 'turn_pre_admitted')->count());
        $this->assertSame(1, $turn->events()->where('event_type', 'pre_admission_deduplicated')->count());
    }

    public function test_unreleased_pre_admission_can_be_abandoned_exactly_once_without_a_final(): void
    {
        $fixture = $this->fixture('clean-abandon@example.com');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $this->admissionInput('clean-abandon-turn-0001'),
        );

        $canceled = $lifecycle->abandonPendingRealtimeInput($turn, 'local_confirmation_rejected');
        $duplicate = $lifecycle->abandonPendingRealtimeInput($canceled, 'duplicate_callback');

        $this->assertSame($canceled->id, $duplicate->id);
        $this->assertSame(VoiceTurnState::Canceled, $duplicate->state);
        $this->assertNotNull($duplicate->terminal_at);
        $this->assertNull($duplicate->final_assistant_message_id);
        $this->assertSame(0, $duplicate->runs()->count());
        $this->assertSame(1, $duplicate->events()->where('event_type', 'turn_canceled')->count());
        $this->assertTrue((bool) data_get(
            $duplicate->events()->where('event_type', 'turn_canceled')->sole()->payload,
            'abandoned_before_provider_input',
        ));
        $this->assertSame(0, ConversationMessage::query()
            ->where('conversation_session_id', $fixture['conversation']->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'assistant')
            ->count());
    }

    public function test_provider_binding_is_the_only_path_to_one_sideband_interpretation_run(): void
    {
        $fixture = $this->fixture('clean-binding@example.com');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $this->admissionInput('clean-provider-bound-turn-0001'),
        );
        $bound = $lifecycle->bindRealtimeInputItem(
            $turn,
            $fixture['realtime'],
            'input_audio_item_0001',
            'provider_event_0001',
        );

        $this->expectException(VoiceTurnConflictException::class);
        try {
            $lifecycle->abandonPendingRealtimeInput($bound);
        } finally {
            $run = $lifecycle->prepareRealtimeInterpretation(
                $bound,
                'Create one task named Buy milk.',
                'input_audio_item_0001',
                'provider_response_0001',
            );
            $duplicate = $lifecycle->prepareRealtimeInterpretation(
                $bound,
                'Create one task named Buy milk.',
                'input_audio_item_0001',
                'provider_response_0001',
            );

            $this->assertSame($run->id, $duplicate->id);
            $this->assertSame(HermesSemanticOperationExecutor::INTERPRETATION_HANDLER, $run->handler);
            $this->assertSame('browser_voice_realtime', $run->source);
            $this->assertSame('Create one task named Buy milk.', $run->input);
            $this->assertSame(1, AssistantRun::query()->where('voice_turn_id', $turn->id)->count());
            $this->assertSame('voice_only', $turn->userMessage()->sole()->display_mode);
            $this->assertSame(0, $fixture['conversation']->messages()->visibleInChat()->count());
        }
    }

    public function test_realtime_clarification_continuation_reuses_the_stable_turn_and_increments_input_generation(): void
    {
        $fixture = $this->fixture('clean-clarification@example.com');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $firstInput = $this->admissionInput('clean-clarification-turn-0001');
        $turn = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $firstInput,
        );
        $bound = $lifecycle->bindRealtimeInputItem($turn, $fixture['realtime'], 'clarify_input_1');
        $firstRun = $lifecycle->prepareRealtimeInterpretation(
            $bound,
            'The user wants a reminder but did not provide a time.',
            'clarify_input_1',
            'clarify_plan_1',
        );
        $this->assertTrue($lifecycle->claimJobExecution($firstRun));
        $awaiting = $lifecycle->requestSemanticClarification(
            $firstRun->fresh(),
            'When should I remind you?',
            ['transport' => 'realtime_sideband'],
        );

        $continuationInput = [...$firstInput, 'input_generation' => 1];
        $sameTurn = $lifecycle->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['realtime'],
            $continuationInput,
        );
        $secondBound = $lifecycle->bindRealtimeInputItem($sameTurn, $fixture['realtime'], 'clarify_input_2');
        $secondRun = $lifecycle->prepareRealtimeInterpretation(
            $secondBound,
            'The reminder time is tomorrow at nine in the morning.',
            'clarify_input_2',
            'clarify_plan_2',
        );

        $this->assertSame($awaiting->id, $sameTurn->id);
        $this->assertSame($turn->turn_id, $secondBound->turn_id);
        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame(2, data_get($secondRun->metadata, 'semantic_sequence'));
        $this->assertSame(2, $turn->runs()->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $fixture['conversation']->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->count());
    }

    /** @return array{user:User,conversation:ConversationSession,realtime:VoiceRealtimeSession} */
    private function fixture(string $email): array
    {
        $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessions = app(RealtimeVoiceSessionService::class);
        $realtime = $sessions->createPending($user, $conversation, 'gpt-realtime-test', 'alloy', 7, [
            'provider_connection_generation' => 3,
        ]);
        $realtime = $sessions->bindProviderCall($realtime, 'call_'.str_replace('@', '_', $email));
        $leased = $sessions->acquireLease($realtime, 'test-daemon', 30);
        $this->assertNotNull($leased);
        $realtime = $sessions->markReady($leased, 'test-daemon');
        $this->assertSame(VoiceRealtimeSessionStatus::Ready, $realtime->status);

        return compact('user', 'conversation', 'realtime');
    }

    /** @return array<string, mixed> */
    private function admissionInput(string $turnId): array
    {
        return [
            'turn_id' => $turnId,
            'controller_generation' => 7,
            'provider_connection_generation' => 3,
            'input_generation' => 0,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
            'client_milestones' => ['wake_detected_at_ms' => 100, 'pre_admission_started_at_ms' => 120],
        ];
    }
}
