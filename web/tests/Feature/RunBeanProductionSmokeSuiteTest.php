<?php

namespace Tests\Feature;

use App\Console\Commands\RunBeanProductionSmokeSuite;
use App\Models\ActivityEvent;
use App\Models\AiUsageLog;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\User;
use App\Services\RealtimeVoiceQualityService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

class RunBeanProductionSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_limit_copy_counts_as_smoke_failure(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'containsFailureCopy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's AI usage limit.",
        ));
        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's external lookup usage limit.",
        ));
        $this->assertFalse($method->invoke(
            $command,
            'Done - I added the three events to your calendar.',
        ));
    }

    public function test_smoke_quality_checks_flag_weak_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertContains('missing_write_confirmation', $method->invoke(
            $command,
            'REQ-001: Create a task to review insurance paperwork tomorrow morning.',
            'I can help with that.',
        ));
        $this->assertContains('missing_weather_details', $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow should be fine.',
        ));
        $this->assertContains('missing_place_details', $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'I found one nearby.',
        ));
        $this->assertContains('wrong_wawa_32820', $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'The nearest Wawa I found near 32820 is Wawa at 6500 Lee Vista Boulevard, Orlando, FL 32822, USA.',
        ));
        $this->assertContains('wrong_place_32820', $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks to 32820 is in Ohio. The address is 123 Main St, Ohio.',
        ));
        $this->assertContains('wrong_starbucks_32820', $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks I found near 32820 is Starbucks at 1 Coffee Rd, Orlando, FL.',
        ));
        $this->assertContains('wrong_home_depot_32820', $method->invoke(
            $command,
            'REQ-076: Find the nearest Home Depot to 32820 and tell me the address quickly.',
            'The nearest Home Depot I found near 32820 is Home Depot at 655 East Colonial Drive, Orlando, FL.',
        ));
        $this->assertContains('missing_memory_confirmation', $method->invoke(
            $command,
            'REQ-081: Remember that I prefer short practical answers unless I ask for detail, then tell me what you saved.',
            'That makes sense.',
        ));
        $this->assertContains('missing_day_context', $method->invoke(
            $command,
            'REQ-091: What do I have coming up today, and if there is empty time after 5pm, suggest a simple plan.',
            'Sounds good.',
        ));
        $this->assertContains('wrong_request_history_dr_chen', $method->invoke(
            $command,
            'REQ-097: What request did I make about Dr Chen Cardio earlier in this smoke run?',
            'Here is what I found in your request history: REQ-073: Find a nearby Wawa around 32820.',
        ));
        $this->assertContains('wrong_request_history_egg_protein', $method->invoke(
            $command,
            'REQ-099: What was my earlier request about Egg Protein Note, if any? If there was none, say so clearly.',
            'Here is what I found in your request history: REQ-053: Create a project follow-up workflow for the budget cleanup.',
        ));
        $this->assertContains('wrong_memory_recall_errand_updates', $method->invoke(
            $command,
            'What did you just save about errand updates?',
            'I saved that preference for you.',
        ));
    }

    public function test_smoke_quality_checks_accept_useful_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(
            $command,
            'REQ-011: Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm.',
            'Done - I added Dr Chen Cardio to your calendar for Jul 9, 3:00 PM, I added Ventura to your calendar for Jul 15, 6:00 PM, and I added Azalea Lane to your calendar for Jul 19, 2:00 PM.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow in Orlando should be stormy. High 94°F, low 76°F, with precipitation possible.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'The nearest Wawa I found near 32820 is Wawa at 16959 E Colonial Dr, Orlando, FL 32820, USA about 1.4 miles away.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-076: Find the nearest Home Depot to 32820 and tell me the address quickly.',
            'The nearest Home Depot I found near 32820 is The Home Depot at 350 N Alafaya Trail, Orlando, FL 32828, USA.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-074: Find the nearest Starbucks to 32820 and tell me the address quickly.',
            'The nearest Starbucks I found near 32820 is Starbucks Coffee Company at 321 Avalon Park S Blvd, Orlando, FL 32828, USA.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-097: What request did I make about Dr Chen Cardio earlier in this smoke run?',
            'You asked: REQ-011: Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-099: What was my earlier request about Egg Protein Note, if any? If there was none, say so clearly.',
            'I checked your request history, but I did not find anything matching that.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'What did you just save about errand updates?',
            'I saved that you prefer concise status updates for errands.',
        ));
    }

    public function test_voice_quality_benchmark_accepts_world_class_telemetry(): void
    {
        $quality = new RealtimeVoiceQualityService;
        $microKinds = ['confirmation', 'decline', 'correction', 'continuation', 'reference'];

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3, 4, 5, 6], true),
                'is_contextual_follow_up_turn' => in_array($index, [2, 3, 4, 5, 6], true),
                'contextual_follow_up_kind' => $microKinds[$index - 2] ?? null,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_success',
                'details' => ['elapsed_ms' => 82, 'ack_budget_ms' => 138],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_queued',
                'details' => [
                    'run_id' => 123,
                    'source' => 'tool_call',
                    'acknowledged' => true,
                    'acknowledgement_character_count' => 18,
                    'queue_elapsed_ms' => 520,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt',
                'details' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'instruction' => 'Give one brief, natural progress update.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'spoken_text' => 'Still working on that for you.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 123,
                    'spoken_character_count' => 42,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_text' => 'Tomorrow has two meetings.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'reference',
                    'function_calls' => [],
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'yes please',
                    'assistant_text' => 'I added it.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'confirmation',
                    'function_calls' => [],
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'no thanks',
                    'assistant_text' => 'No problem.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'decline',
                    'function_calls' => [],
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'wrong one',
                    'assistant_text' => 'Which one did you mean?',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'correction',
                    'function_calls' => [],
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'keep going',
                    'assistant_text' => 'The next item is your lunch meeting.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'continuation',
                    'function_calls' => [],
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in_recovered',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_answered' => true,
                    'has_user_content' => true,
                    'function_call_count' => 0,
                    'response_id' => 'resp_followup',
                    'recovery_elapsed_ms' => 980,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 16,
                    'interrupted_internal_prompt' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 12,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'reference',
                    'turn_completed_ms' => 1110,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 13,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'confirmation',
                    'turn_completed_ms' => 1111,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 14,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'decline',
                    'turn_completed_ms' => 1112,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 15,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'correction',
                    'turn_completed_ms' => 1113,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 16,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'continuation',
                    'turn_completed_ms' => 1114,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_pending_response_deferred_by_speech',
                'details' => [
                    'user_content' => 'what is next',
                    'response_create_was_in_flight' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_pending_response_recovered_after_non_actionable_speech',
                'details' => [
                    'user_content' => 'what is next',
                    'transcript' => '',
                    'synthetic' => false,
                    'recovery_elapsed_ms' => 260,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_audio_done_ready',
                'details' => [
                    'response_id' => 'resp_fast_2',
                    'ready_elapsed_ms' => 0,
                    'status' => 'listening',
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'transcription_only_release_pending' => false,
                    'background_work_active' => false,
                    'audio_elapsed_ms' => 1180,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('siri_alexa_voice_responsiveness', $summary['benchmark']);
        $this->assertSame('pass', data_get($summary, 'gate.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.latency.status'));
        $this->assertSame(700, data_get($summary, 'gate.requirements.latency.targets.p50_transcript_to_first_assistant_ms'));
        $this->assertSame(1200, data_get($summary, 'gate.requirements.latency.targets.p95_transcript_to_first_assistant_ms'));
        $this->assertSame(5000, data_get($summary, 'gate.requirements.latency.targets.p95_full_turn_ms'));
        $this->assertSame(430, data_get($summary, 'gate.requirements.latency.observed.p95_transcript_to_first_assistant_ms'));
        $this->assertSame(1110, data_get($summary, 'gate.requirements.latency.observed.p95_full_turn_ms'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.barge_in_interruption_recovery.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.fresh_context_accuracy.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.contextual_followups.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.natural_voice.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.live_session_reliability.status'));
        $this->assertSame([], data_get($summary, 'gate.failures'));
        $this->assertSame(10, data_get($summary, 'window.turn_sample_size'));
        $this->assertSame('pass', data_get($summary, 'metrics.transcript_to_first_assistant_ms.status'));
        $this->assertSame('pass', data_get($summary, 'speech.brevity.status'));
        $this->assertSame('pass', data_get($summary, 'speech.naturalness.status'));
        $this->assertSame(7, data_get($summary, 'speech.naturalness.sample_size'));
        $this->assertSame(0, data_get($summary, 'speech.naturalness.violation_count'));
        $this->assertSame('pass', data_get($summary, 'conversation.status'));
        $this->assertSame(5, data_get($summary, 'conversation.follow_up_turn_count'));
        $this->assertSame(5, data_get($summary, 'conversation.contextual_follow_up_turn_count'));
        $this->assertSame(5, data_get($summary, 'conversation.micro_follow_up_kind_sample_size'));
        $this->assertSame(5, data_get($summary, 'conversation.micro_follow_up_kind_count'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.reference'));
        $this->assertSame(0, data_get($summary, 'conversation.untyped_contextual_follow_up_count'));
        $this->assertSame('pass', data_get($summary, 'contextual_follow_up_resolution.status'));
        $this->assertSame(5, data_get($summary, 'contextual_follow_up_resolution.resolved_count'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_quality.sample_size'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_quality.internal_prompt_count'));
        $this->assertSame(1, data_get($summary, 'events.minimum_barge_in_count'));
        $this->assertSame('pass', data_get($summary, 'events.barge_in_recovery_quality.status'));
        $this->assertSame(980, data_get($summary, 'events.barge_in_recovery_quality.p95_recovery_elapsed_ms'));
        $this->assertSame(0, data_get($summary, 'events.barge_in_recovery_quality.missing_response_id_count'));
        $this->assertSame(1, data_get($summary, 'events.minimum_barge_in_recovery_count'));
        $this->assertSame('pass', data_get($summary, 'events.follow_up_readiness_quality.status'));
        $this->assertSame(16, data_get($summary, 'events.follow_up_readiness_quality.p95_ready_elapsed_ms'));
        $this->assertSame(5, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_count'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.reference'));
        $this->assertSame(0, data_get($summary, 'events.follow_up_readiness_quality.untyped_contextual_follow_up_ready_count'));
        $this->assertSame(1, data_get($summary, 'events.minimum_follow_up_ready_count'));
        $this->assertSame('pass', data_get($summary, 'events.pending_response_recovery_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.pending_response_recovery_quality.sample_size'));
        $this->assertSame(1, data_get($summary, 'events.pending_response_recovery_quality.recovered_count'));
        $this->assertSame(0, data_get($summary, 'events.pending_response_recovery_quality.unrecovered_count'));
        $this->assertSame(260, data_get($summary, 'events.pending_response_recovery_quality.p95_recovery_elapsed_ms'));
        $this->assertSame(1, data_get($summary, 'events.minimum_pending_response_recovery_count'));
        $this->assertSame('pass', data_get($summary, 'events.audio_done_readiness_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.audio_done_readiness_quality.p95_ready_elapsed_ms'));
        $this->assertSame(1, data_get($summary, 'events.minimum_audio_done_ready_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_success_count'));
        $this->assertSame(1, data_get($summary, 'events.minimum_context_refresh_success_count'));
        $this->assertSame('pass', data_get($summary, 'events.context_refresh_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.success_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.timeout_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.failure_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.ack_timeout_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.routed_to_background_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.background_queued_recovery_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.background_completed_recovery_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.recovery_completion_missing_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.unrecovered_failure_count'));
        $this->assertSame(82, data_get($summary, 'events.context_refresh_quality.p95_elapsed_ms'));
        $this->assertSame('pass', data_get($summary, 'events.background_queue_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.background_queue_quality.fallback_count'));
        $this->assertSame(0, data_get($summary, 'events.background_queue_quality.target_fallback_count'));
        $this->assertSame(520, data_get($summary, 'events.background_queue_quality.p95_queue_elapsed_ms'));
        $this->assertSame('pass', data_get($summary, 'events.background_progress_quality.status'));
        $this->assertSame(8000, data_get($summary, 'events.background_progress_quality.p95_first_progress_elapsed_ms'));
        $this->assertSame(0, data_get($summary, 'events.background_progress_quality.duplicate_count'));
        $this->assertSame(0, data_get($summary, 'events.background_progress_quality.target_duplicate_count'));
        $this->assertSame(0, data_get($summary, 'events.background_progress_prompt_skipped_count'));
        $this->assertSame(1, data_get($summary, 'events.minimum_background_progress_prompt_count'));
        $this->assertSame(1, data_get($summary, 'events.background_completed_count'));
        $this->assertSame('pass', data_get($summary, 'events.background_completion_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.background_completion_quality.duplicate_count'));
        $this->assertSame(0, data_get($summary, 'events.background_completion_quality.spoken_text_incomplete_count'));
        $this->assertSame(0, data_get($summary, 'events.background_silent_completion_count'));
        $this->assertSame(0, data_get($summary, 'events.background_completed_after_voice_closed_count'));
        $this->assertSame(0, data_get($summary, 'events.background_completion_deferred_count'));
        $this->assertSame('pass', data_get($summary, 'events.background_completion_status'));
        $this->assertSame(0, data_get($summary, 'events.background_cancelled_after_voice_closed_count'));
        $this->assertSame('pass', data_get($summary, 'events.background_cancelled_after_voice_closed_status'));
        $this->assertSame(0, data_get($summary, 'events.background_watch_failure_count'));
        $this->assertSame('pass', data_get($summary, 'events.background_watch_failure_status'));
        $this->assertSame(0, data_get($summary, 'events.interrupt_signal_failure_count'));
        $this->assertSame('pass', data_get($summary, 'events.interrupt_signal_status'));
        $this->assertSame(0, data_get($summary, 'events.realtime_error_count'));
        $this->assertSame('pass', data_get($summary, 'events.realtime_error_status'));
        $this->assertSame(0, data_get($summary, 'events.response_failure_count'));
        $this->assertSame('pass', data_get($summary, 'events.response_failure_status'));
        $this->assertSame('pass', data_get($summary, 'events.unanswered_response_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.unanswered_response_quality.unanswered_count'));
        $this->assertSame(0, data_get($summary, 'events.tool_call_failure_count'));
        $this->assertSame('pass', data_get($summary, 'events.tool_call_failure_status'));
        $this->assertSame(0, data_get($summary, 'events.tool_fallback_failure_count'));
        $this->assertSame('pass', data_get($summary, 'events.tool_fallback_failure_status'));
        $this->assertSame(0, data_get($summary, 'events.unsupported_direct_answer_count'));
        $this->assertSame('pass', data_get($summary, 'events.unsupported_direct_answer_status'));
        $this->assertSame('pass', data_get($summary, 'events.unsupported_direct_answer_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.unsupported_direct_answer_quality.missing_fresh_context_count'));
        $this->assertSame(0, data_get($summary, 'events.unsupported_direct_answer_quality.background_required_count'));
        $this->assertSame(0, data_get($summary, 'events.unsupported_direct_answer_queued_count'));
        $this->assertSame(10, data_get($summary, 'telemetry.usage_sample_size'));
        $this->assertSame([], $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_requires_barge_in_evidence(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));

        $summary = $quality->benchmarkSummary(
            $turns,
            collect(),
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('needs_attention', $summary['status']);
        $this->assertSame('no_data', data_get($summary, 'events.barge_in_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.barge_in_quality.sample_size'));
        $this->assertSame(1, data_get($summary, 'events.minimum_barge_in_count'));
        $this->assertContains('voice_spoken_naturalness_missing:0/3', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_follow_up_ready_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_barge_in_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_barge_in_recovery_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_context_refresh_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_queue_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_completion_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_progress_missing:0/1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_unacknowledged_background_queue(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_success',
                'details' => ['elapsed_ms' => 82, 'ack_budget_ms' => 138],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_queued',
                'details' => [
                    'run_id' => 123,
                    'source' => 'fallback',
                    'acknowledged' => false,
                    'acknowledgement_character_count' => 0,
                    'queue_elapsed_ms' => 2100,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 123,
                    'spoken_character_count' => 42,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 16,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('needs_attention', $summary['status']);
        $this->assertSame('fail', data_get($summary, 'events.background_queue_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.background_queue_quality.fallback_count'));
        $this->assertSame(1, data_get($summary, 'events.background_queue_quality.unacknowledged_count'));
        $this->assertContains('voice_background_unacknowledged:1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_fallback_queue:1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_queue_latency:2100', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_missing_first_background_progress_prompt(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt',
                'details' => ['elapsed_ms' => 18000],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_progress_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.background_progress_quality.first_progress_sample_size'));
        $this->assertSame(0, data_get($summary, 'events.background_progress_quality.spoken_sample_size'));
        $this->assertContains('voice_background_progress_spoken_telemetry_incomplete:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_background_first_progress_missing', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_repeated_background_progress_prompts(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt',
                'details' => [
                    'elapsed_ms' => 8000,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => [
                    'elapsed_ms' => 8000,
                    'spoken_text' => 'I am still working on that.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => [
                    'elapsed_ms' => 11000,
                    'spoken_text' => 'I am still working on that.',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_progress_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.background_progress_quality.duplicate_count'));
        $this->assertContains('voice_background_progress_duplicate:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_verbose_background_progress_prompts(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt',
                'details' => [
                    'elapsed_ms' => 8000,
                    'instruction' => 'Give one brief progress update.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => [
                    'elapsed_ms' => 8000,
                    'spoken_text' => 'I am still working on that. It is taking a little longer than expected, and I will keep checking while you wait.',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_progress_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.background_progress_quality.brevity_violation_count'));
        $this->assertSame(2, data_get($summary, 'events.background_progress_quality.max_spoken_sentence_count'));
        $this->assertSame(1, data_get($summary, 'events.background_progress_quality.target_max_spoken_sentence_count'));
        $this->assertContains('voice_background_progress_brevity:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_repeated_background_completion_voice(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 1,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                    'spoken_character_count' => 51,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 2,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                    'spoken_character_count' => 51,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_completion_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.background_completion_quality.duplicate_count'));
        $this->assertContains('voice_background_completion_duplicate:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_verbose_background_completion_voice(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 1,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon. I also found an open slot on Friday afternoon. I put the longer notes in chat.',
                    'spoken_character_count' => 126,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_completion_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.background_completion_quality.brevity_violation_count'));
        $this->assertSame(3, data_get($summary, 'events.background_completion_quality.max_spoken_sentence_count'));
        $this->assertSame(2, data_get($summary, 'events.background_completion_quality.target_max_spoken_sentence_count'));
        $this->assertContains('voice_background_completion_brevity:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_background_completion_missing_spoken_text(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 1,
                    'spoken_character_count' => 42,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_completion_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.background_completion_quality.spoken_text_sample_size'));
        $this->assertSame(1, data_get($summary, 'events.background_completion_quality.spoken_text_incomplete_count'));
        $this->assertContains('voice_background_completion_spoken_telemetry_incomplete:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_separates_background_completion_after_voice_closed(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed_after_voice_closed',
                'details' => ['run_id' => 123, 'spoken_character_count' => 0],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );
        $failures = $quality->benchmarkFailures($summary);

        $this->assertSame(0, data_get($summary, 'events.background_completed_count'));
        $this->assertSame(1, data_get($summary, 'events.background_completed_after_voice_closed_count'));
        $this->assertSame(0, data_get($summary, 'events.background_silent_completion_count'));
        $this->assertContains('voice_background_completion_missing:0/1', $failures);
        $this->assertNotContains('voice_background_silent_completion:1', $failures);
    }

    public function test_voice_quality_benchmark_requires_internal_prompt_barge_in_evidence(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 16,
                    'interrupted_internal_prompt' => false,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.barge_in_quality.status'));
        $this->assertSame(0, data_get($summary, 'events.barge_in_quality.internal_prompt_count'));
        $this->assertContains('voice_internal_prompt_barge_in_missing:0/1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_failed_barge_in_recovery(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in_recovery_failed',
                'details' => [
                    'reason' => 'empty_transcript',
                    'transcript' => '',
                    'assistant_answered' => false,
                    'has_user_content' => false,
                    'recovery_elapsed_ms' => 700,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.barge_in_recovery_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_recovery_quality.sample_size'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_recovery_quality.incomplete_count'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_recovery_quality.failed_count'));
        $this->assertContains('voice_barge_in_recovery_incomplete:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_requires_barge_in_recovery_response_id(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in_recovered',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_answered' => true,
                    'has_user_content' => true,
                    'function_call_count' => 0,
                    'recovery_elapsed_ms' => 980,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.barge_in_recovery_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_recovery_quality.incomplete_count'));
        $this->assertSame(1, data_get($summary, 'events.barge_in_recovery_quality.missing_response_id_count'));
        $this->assertContains('voice_barge_in_recovery_missing_response_id:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_unrecovered_pending_response_interruption(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_pending_response_deferred_by_speech',
                'details' => [
                    'user_content' => 'what is next',
                    'response_create_was_in_flight' => true,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.pending_response_recovery_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.pending_response_recovery_quality.sample_size'));
        $this->assertSame(0, data_get($summary, 'events.pending_response_recovery_quality.recovered_count'));
        $this->assertSame(1, data_get($summary, 'events.pending_response_recovery_quality.unrecovered_count'));
        $this->assertContains('voice_pending_response_recovery_missing:0/1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_pending_response_unrecovered:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_slow_context_refresh_success(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_success',
                'details' => ['elapsed_ms' => 260, 'ack_budget_ms' => 0],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 16,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('needs_attention', $summary['status']);
        $this->assertSame('fail', data_get($summary, 'events.context_refresh_quality.status'));
        $this->assertSame(260, data_get($summary, 'events.context_refresh_quality.p95_elapsed_ms'));
        $this->assertContains('voice_context_refresh_latency:260', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_tracks_context_refresh_failure_recovery(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_timeout',
                'details' => ['budget_ms' => 220],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_routed_to_background',
                'details' => [
                    'user_content' => 'what is on my calendar today',
                    'reason' => 'timeout',
                    'fallback_item_sent' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_queued',
                'details' => [
                    'run_id' => 321,
                    'source' => 'tool_call',
                    'acknowledged' => true,
                    'context_refresh_recovery' => true,
                    'context_refresh_failure_reason' => 'timeout',
                    'user_content' => 'what is on my calendar today',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 321,
                    'spoken_character_count' => 38,
                    'spoken_text' => 'You have lunch at noon today.',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );
        $failures = $quality->benchmarkFailures($summary);

        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.timeout_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.routed_to_background_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.background_queued_recovery_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.background_completed_recovery_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.recovery_completion_missing_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.unrecovered_failure_count'));
        $this->assertContains('voice_context_freshness_failure:1', $failures);
        $this->assertNotContains('voice_context_freshness_unrecovered:1', $failures);
        $this->assertNotContains('voice_context_refresh_recovery_completion_missing:1', $failures);
    }

    public function test_voice_quality_benchmark_flags_context_refresh_recovery_without_spoken_completion(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_timeout',
                'details' => ['budget_ms' => 220],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'dashboard_context_pre_response_routed_to_background',
                'details' => [
                    'user_content' => 'what is on my calendar today',
                    'reason' => 'timeout',
                    'fallback_item_sent' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_queued',
                'details' => [
                    'run_id' => 654,
                    'source' => 'tool_call',
                    'acknowledged' => true,
                    'context_refresh_recovery' => true,
                    'context_refresh_failure_reason' => 'timeout',
                    'user_content' => 'what is on my calendar today',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );
        $failures = $quality->benchmarkFailures($summary);

        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.background_queued_recovery_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.background_completed_recovery_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.recovery_completion_missing_count'));
        $this->assertContains('voice_context_refresh_recovery_completion_missing:1', $failures);
    }

    public function test_voice_quality_benchmark_flags_misleading_background_failure_voice(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 110,
                'response_create_to_first_assistant_ms' => 230,
                'transcript_to_first_assistant_ms' => 430,
                'turn_completed_ms' => 1200,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_failed',
                'details' => [
                    'run_id' => 321,
                    'failure_voice_text' => 'I am checking the latest app state now.',
                    'failure_voice_acknowledged' => false,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_complete_empty',
                'details' => [
                    'run_id' => 322,
                    'failure_voice_text' => 'Still working on it.',
                    'failure_voice_acknowledged' => false,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'events.background_failure_truthfulness.status'));
        $this->assertSame(2, data_get($summary, 'events.background_failure_truthfulness.misleading_count'));
        $this->assertContains('voice_background_failure_misleading:2', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_non_human_spoken_disclaimers(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 110,
                'response_create_to_first_assistant_ms' => 230,
                'transcript_to_first_assistant_ms' => 430,
                'turn_completed_ms' => 1200,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'assistant_text' => 'As an AI language model, I cannot access your calendar.',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'assistant_text' => "I can't access your reminders, but I can help with something else.",
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'assistant_text' => 'I can read you.',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'speech.naturalness.status'));
        $this->assertSame(3, data_get($summary, 'speech.naturalness.violation_count'));
        $patterns = collect(data_get($summary, 'speech.naturalness.violations'))->pluck('matched_pattern')->all();
        $this->assertContains('ai_disclaimer', $patterns);
        $this->assertContains('false_app_capability_denial', $patterns);
        $this->assertContains('bad_voice_mic_check', $patterns);
        $this->assertContains('voice_spoken_naturalness_violation:3', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_repeated_spoken_responses(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 110,
                'response_create_to_first_assistant_ms' => 230,
                'transcript_to_first_assistant_ms' => 430,
                'turn_completed_ms' => 1200,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['id' => 11, 'metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => ['assistant_text' => "I'm still working on that."],
            ]]),
            new AiUsageLog(['id' => 12, 'metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => ['assistant_text' => "I'm still working on that."],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'speech.naturalness.status'));
        $this->assertSame(1, data_get($summary, 'speech.naturalness.duplicate_response_count'));
        $this->assertSame("I'm still working on that.", data_get($summary, 'speech.naturalness.duplicate_responses.0.assistant_text'));
        $this->assertContains('voice_spoken_duplicate_response:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_uses_dedicated_voice_only_spoken_events(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 110,
                'response_create_to_first_assistant_ms' => 230,
                'transcript_to_first_assistant_ms' => 430,
                'turn_completed_ms' => 1200,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'assistant_text' => 'I will call the queue_bean_work tool now.',
                    'voice_only_assistant' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => ['spoken_text' => 'Still working on that for you.'],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('pass', data_get($summary, 'speech.naturalness.status'));
        $this->assertSame(1, data_get($summary, 'speech.naturalness.sample_size'));
        $this->assertSame(0, data_get($summary, 'speech.naturalness.violation_count'));
        $this->assertContains('voice_spoken_naturalness_missing:1/3', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_scores_background_completion_speech(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 110,
                'response_create_to_first_assistant_ms' => 230,
                'transcript_to_first_assistant_ms' => 430,
                'turn_completed_ms' => 1200,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 42,
                    'spoken_text' => 'The tool returned the calendar result.',
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'speech.naturalness.status'));
        $this->assertSame(1, data_get($summary, 'speech.naturalness.sample_size'));
        $this->assertSame(1, data_get($summary, 'speech.naturalness.violation_count'));
        $this->assertSame('realtime_background_completed', data_get($summary, 'speech.naturalness.violations.0.event_type'));
        $this->assertSame('tool_result', data_get($summary, 'speech.naturalness.violations.0.matched_pattern'));
        $this->assertContains('voice_spoken_naturalness_missing:1/3', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_spoken_naturalness_violation:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_breaks_out_stale_context_direct_answers(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 120,
                'response_create_to_first_assistant_ms' => 250,
                'transcript_to_first_assistant_ms' => 460,
                'turn_completed_ms' => 1300,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_unsupported_direct_answer',
                'details' => [
                    'reason' => 'missing_fresh_context',
                    'user_content' => 'what is on my calendar today',
                    'assistant_text' => 'You have lunch at noon.',
                    'context_refresh_succeeded' => false,
                    'concrete_answer' => true,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            includeRecentSlowTurns: false,
        );
        $failures = $quality->benchmarkFailures($summary);

        $this->assertSame('fail', data_get($summary, 'events.unsupported_direct_answer_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.unsupported_direct_answer_quality.missing_fresh_context_count'));
        $this->assertSame(0, data_get($summary, 'events.unsupported_direct_answer_quality.background_required_count'));
        $this->assertSame('missing_fresh_context', data_get($summary, 'events.unsupported_direct_answer_quality.examples.0.reason'));
        $this->assertContains('voice_unsupported_direct_answer:1', $failures);
        $this->assertContains('voice_stale_context_direct_answer:1', $failures);
    }

    public function test_voice_quality_benchmark_flags_unresolved_contextual_follow_up_response(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect(range(1, 10))->map(fn (int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => in_array($index, [2, 3], true),
                'is_contextual_follow_up_turn' => $index === 2,
            ],
        ]));
        $events = collect([
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_text' => '',
                    'assistant_answered' => false,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'function_calls' => [],
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 10,
            includeRecentSlowTurns: false,
        );

        $this->assertSame('fail', data_get($summary, 'contextual_follow_up_resolution.status'));
        $this->assertSame(1, data_get($summary, 'contextual_follow_up_resolution.unresolved_count'));
        $this->assertSame('fail', data_get($summary, 'events.unanswered_response_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.unanswered_response_quality.unanswered_count'));
        $this->assertSame('what about tomorrow', data_get($summary, 'events.unanswered_response_quality.examples.0.user_content'));
        $this->assertContains('voice_unanswered_response:1', $quality->benchmarkFailures($summary));
        $this->assertContains('voice_contextual_follow_up_unresolved:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_summarizes_micro_follow_up_kinds(): void
    {
        $quality = new RealtimeVoiceQualityService;
        $kinds = ['confirmation', 'decline', 'correction', 'continuation', 'reference'];

        $turns = collect($kinds)->map(fn (string $kind, int $index): AiUsageLog => new AiUsageLog([
            'metadata' => [
                'transcript_to_response_create_ms' => 100 + $index,
                'response_create_to_first_assistant_ms' => 220 + $index,
                'transcript_to_first_assistant_ms' => 420 + $index,
                'turn_completed_ms' => 1100 + $index,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => true,
                'is_contextual_follow_up_turn' => true,
                'contextual_follow_up_kind' => $kind,
            ],
        ]))->push(new AiUsageLog(['metadata' => [
            'transcript_to_response_create_ms' => 120,
            'response_create_to_first_assistant_ms' => 240,
            'transcript_to_first_assistant_ms' => 440,
            'turn_completed_ms' => 1120,
            'spoken_brevity_violation' => false,
            'realtime_usage_missing' => false,
            'is_follow_up_turn' => true,
            'is_contextual_follow_up_turn' => true,
        ]]));

        $summary = $quality->benchmarkSummary(
            $turns,
            collect(),
            7,
            minimumTurns: 1,
            includeRecentSlowTurns: false,
        );

        $this->assertSame(6, data_get($summary, 'conversation.contextual_follow_up_turn_count'));
        $this->assertSame(5, data_get($summary, 'conversation.micro_follow_up_kind_sample_size'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'conversation.micro_follow_up_kind_counts.reference'));
        $this->assertSame(1, data_get($summary, 'conversation.untyped_contextual_follow_up_count'));
        $this->assertContains('voice_untyped_contextual_follow_up:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_summarizes_micro_follow_up_readiness_kinds(): void
    {
        $quality = new RealtimeVoiceQualityService;
        $events = collect(['confirmation', 'decline', 'correction', 'continuation', 'reference'])
            ->map(fn (string $kind, int $index): AiUsageLog => new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 10 + $index,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => $kind,
                ],
            ]]))
            ->push(new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 20,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                ],
            ]]));

        $summary = $quality->benchmarkSummary(
            collect([new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 100,
                'response_create_to_first_assistant_ms' => 220,
                'transcript_to_first_assistant_ms' => 420,
                'turn_completed_ms' => 1100,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
                'is_follow_up_turn' => false,
                'is_contextual_follow_up_turn' => false,
            ]])]),
            $events,
            7,
            minimumTurns: 1,
            includeRecentSlowTurns: false,
        );

        $this->assertSame(6, data_get($summary, 'events.follow_up_readiness_quality.sample_size'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.reference'));
        $this->assertSame(1, data_get($summary, 'events.follow_up_readiness_quality.untyped_contextual_follow_up_ready_count'));
        $this->assertContains('voice_untyped_contextual_follow_up_ready:1', $quality->benchmarkFailures($summary));
    }

    public function test_voice_quality_benchmark_flags_latency_brevity_and_telemetry_failures(): void
    {
        $quality = new RealtimeVoiceQualityService;

        $turns = collect([
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 180,
                'response_create_to_first_assistant_ms' => 340,
                'transcript_to_first_assistant_ms' => 520,
                'turn_completed_ms' => 1400,
                'spoken_brevity_violation' => false,
                'realtime_usage_missing' => false,
            ]]),
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 700,
                'response_create_to_first_assistant_ms' => 1100,
                'transcript_to_first_assistant_ms' => 1500,
                'turn_completed_ms' => 6100,
                'spoken_brevity_violation' => true,
                'realtime_usage_missing' => false,
            ]]),
            new AiUsageLog(['metadata' => [
                'transcript_to_response_create_ms' => 900,
                'response_create_to_first_assistant_ms' => 1300,
                'transcript_to_first_assistant_ms' => 1800,
                'turn_completed_ms' => 7200,
                'spoken_brevity_violation' => false,
            ]]),
        ]);
        $events = collect([
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_background_cancel_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_background_failed']]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_watch_failure',
                'details' => [
                    'run_id' => 42,
                    'reason' => 'poll_error',
                    'attempt' => 8,
                    'failure_voice_acknowledged' => true,
                    'failure_voice_delivered' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'realtime_background_complete_empty',
                'details' => [
                    'run_id' => 43,
                    'failure_voice_acknowledged' => true,
                    'failure_voice_delivered' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_background_completed_without_voice']]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_background_cancelled_after_voice_closed']]),
            new AiUsageLog(['metadata' => ['event_type' => 'flutter_realtime_in_flight_cancel_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'flutter_realtime_interrupt_signal_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_error']]),
            new AiUsageLog(['metadata' => ['event_type' => 'flutter_realtime_response_failed']]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_tool_call_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'realtime_tool_fallback_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'flutter_realtime_premature_completion_claim']]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_unsupported_direct_answer',
                'details' => [
                    'reason' => 'background_required',
                    'user_content' => 'schedule lunch with Sam tomorrow',
                    'assistant_text' => 'I scheduled that for tomorrow.',
                    'context_refresh_succeeded' => false,
                    'concrete_answer' => true,
                ],
            ]]),
            new AiUsageLog(['metadata' => ['event_type' => 'ice_webrtc_connection_failure']]),
            new AiUsageLog(['metadata' => ['event_type' => 'dashboard_context_pre_response_timeout']]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => ['assistant_text' => 'I will call the queue_bean_work tool now.'],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in_recovered',
                'details' => [
                    'user_content' => '',
                    'assistant_answered' => false,
                    'has_user_content' => false,
                    'function_call_count' => 1,
                    'recovery_elapsed_ms' => 3600,
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => false,
                    'output_audio_cleared' => false,
                    'truncate_attempted' => true,
                    'truncate_sent' => false,
                    'cancel_dispatch_ms' => 140,
                    'dispatch_error' => 'data_channel_unavailable',
                ],
            ]]),
            new AiUsageLog(['metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 240,
                    'conversation_active' => true,
                    'mic_enabled' => false,
                    'microphone_track_count' => 1,
                ],
            ]]),
        ]);

        $summary = $quality->benchmarkSummary(
            $turns,
            $events,
            7,
            minimumTurns: 5,
            includeRecentSlowTurns: false,
        );
        $failures = $quality->benchmarkFailures($summary);

        $this->assertContains('voice_insufficient_sample:3/5', $failures);
        $this->assertContains('voice_metric_fail:transcript_to_first_assistant_ms', $failures);
        $this->assertContains('voice_metric_fail:response_create_to_first_assistant_ms', $failures);
        $this->assertContains('voice_metric_fail:turn_completed_ms', $failures);
        $this->assertContains('voice_metric_fail:transcript_to_response_create_ms', $failures);
        $this->assertContains('voice_brevity_fail', $failures);
        $this->assertContains('voice_spoken_naturalness_violation:1', $failures);
        $this->assertContains('voice_usage_telemetry_incomplete:2/3', $failures);
        $this->assertContains('voice_follow_up_telemetry_incomplete:0/3', $failures);
        $this->assertContains('voice_follow_up_missing:0/2', $failures);
        $this->assertContains('voice_contextual_follow_up_missing:0/1', $failures);
        $this->assertContains('voice_micro_follow_up_kinds_missing:0/5', $failures);
        $this->assertContains('voice_contextual_follow_up_resolution_missing:0/1', $failures);
        $this->assertContains('voice_follow_up_ready_incomplete:1', $failures);
        $this->assertContains('voice_follow_up_ready_latency:240', $failures);
        $this->assertContains('voice_micro_follow_up_ready_kinds_missing:0/5', $failures);
        $this->assertContains('voice_audio_done_ready_missing:0/1', $failures);
        $this->assertContains('voice_background_queue_missing:0/1', $failures);
        $this->assertContains('voice_background_completion_missing:0/1', $failures);
        $this->assertContains('voice_background_progress_missing:0/1', $failures);
        $this->assertContains('voice_background_failure:3', $failures);
        $this->assertContains('voice_background_watch_failure:1', $failures);
        $this->assertContains('voice_background_failure_truthfulness_incomplete:1', $failures);
        $this->assertContains('voice_background_silent_completion:1', $failures);
        $this->assertContains('voice_background_cancelled_after_voice_closed:1', $failures);
        $this->assertContains('voice_background_cancel_failure:1', $failures);
        $this->assertContains('voice_in_flight_cancel_failure:1', $failures);
        $this->assertContains('voice_interrupt_signal_failure:1', $failures);
        $this->assertContains('voice_realtime_error:1', $failures);
        $this->assertContains('voice_response_failure:1', $failures);
        $this->assertContains('voice_tool_call_failure:1', $failures);
        $this->assertContains('voice_tool_fallback_failure:1', $failures);
        $this->assertContains('voice_premature_completion_claim:1', $failures);
        $this->assertContains('voice_unsupported_direct_answer:1', $failures);
        $this->assertContains('voice_background_required_direct_answer:1', $failures);
        $this->assertContains('voice_transport_failure:1', $failures);
        $this->assertContains('voice_context_freshness_failure:1', $failures);
        $this->assertContains('voice_context_freshness_unrecovered:1', $failures);
        $this->assertSame('fail', data_get($summary, 'events.context_refresh_quality.status'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.timeout_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.routed_to_background_count'));
        $this->assertSame(0, data_get($summary, 'events.context_refresh_quality.background_queued_recovery_count'));
        $this->assertSame(1, data_get($summary, 'events.context_refresh_quality.unrecovered_failure_count'));
        $this->assertContains('voice_context_refresh_missing:0/1', $failures);
        $this->assertContains('voice_internal_prompt_barge_in_missing:0/1', $failures);
        $this->assertSame(1, data_get($summary, 'events.barge_in_quality.dispatch_error_count'));
        $this->assertSame('data_channel_unavailable', data_get($summary, 'events.barge_in_quality.dispatch_errors.0.dispatch_error'));
        $this->assertFalse((bool) data_get($summary, 'events.barge_in_quality.dispatch_errors.0.cancel_sent'));
        $this->assertContains('voice_barge_in_incomplete:1', $failures);
        $this->assertContains('voice_barge_in_latency:140', $failures);
        $this->assertContains('voice_barge_in_recovery_incomplete:1', $failures);
        $this->assertContains('voice_barge_in_recovery_latency:3600', $failures);
    }

    public function test_voice_quality_smoke_command_emits_reproducible_verification_metadata(): void
    {
        $email = 'voice-quality-command@example.com';
        $suiteId = 'voice-quality-suite';
        $user = User::factory()->create(['email' => $email]);
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $microKinds = ['confirmation', 'decline', 'correction', 'continuation', 'reference'];

        foreach (range(1, 10) as $index) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime',
                'request_type' => 'realtime_voice',
                'status' => 'completed',
                'metadata' => [
                    'transcript_to_response_create_ms' => 100 + $index,
                    'response_create_to_first_assistant_ms' => 220 + $index,
                    'transcript_to_first_assistant_ms' => 420 + $index,
                    'turn_completed_ms' => 1100 + $index,
                    'spoken_brevity_violation' => false,
                    'realtime_usage_missing' => false,
                    'is_follow_up_turn' => in_array($index, [2, 3, 4, 5, 6], true),
                    'is_contextual_follow_up_turn' => in_array($index, [2, 3, 4, 5, 6], true),
                    'contextual_follow_up_kind' => $microKinds[$index - 2] ?? null,
                ],
            ]);
        }

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_barge_in',
                'details' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 16,
                    'interrupted_internal_prompt' => true,
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_pending_response_deferred_by_speech',
                'details' => [
                    'user_content' => 'what is next',
                    'response_create_was_in_flight' => true,
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_pending_response_recovered_after_non_actionable_speech',
                'details' => [
                    'user_content' => 'what is next',
                    'transcript' => '',
                    'synthetic' => false,
                    'recovery_elapsed_ms' => 260,
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt_spoken',
                'details' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'spoken_text' => 'Still working on that for you.',
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_progress_prompt',
                'details' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'instruction' => 'Give one brief, natural progress update.',
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'dashboard_context_pre_response_success',
                'details' => ['elapsed_ms' => 82, 'ack_budget_ms' => 138],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'realtime_background_queued',
                'details' => [
                    'run_id' => 123,
                    'source' => 'tool_call',
                    'acknowledged' => true,
                    'acknowledgement_character_count' => 18,
                    'queue_elapsed_ms' => 520,
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'realtime_background_completed',
                'details' => [
                    'run_id' => 123,
                    'spoken_character_count' => 42,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_response_done',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_text' => 'Tomorrow has two meetings.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'reference',
                    'function_calls' => [],
                ],
            ],
        ]);

        foreach ([
            ['yes please', 'I added it.', 'confirmation'],
            ['no thanks', 'No problem.', 'decline'],
            ['wrong one', 'Which one did you mean?', 'correction'],
            ['keep going', 'The next item is your lunch meeting.', 'continuation'],
        ] as [$userContent, $assistantText, $kind]) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime',
                'request_type' => 'realtime_voice_event',
                'status' => 'completed',
                'metadata' => [
                    'event_type' => 'flutter_realtime_response_done',
                    'details' => [
                        'user_content' => $userContent,
                        'assistant_text' => $assistantText,
                        'assistant_answered' => true,
                        'is_follow_up_turn' => true,
                        'is_contextual_follow_up_turn' => true,
                        'contextual_follow_up_kind' => $kind,
                        'function_calls' => [],
                    ],
                ],
            ]);
        }

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_barge_in_recovered',
                'details' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_answered' => true,
                    'has_user_content' => true,
                    'function_call_count' => 0,
                    'response_id' => 'resp_followup',
                    'recovery_elapsed_ms' => 980,
                ],
            ],
        ]);

        foreach ($microKinds as $index => $kind) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime',
                'request_type' => 'realtime_voice_event',
                'status' => 'completed',
                'metadata' => [
                    'event_type' => 'flutter_realtime_followup_ready',
                    'details' => [
                        'ready_elapsed_ms' => 12 + $index,
                        'conversation_active' => true,
                        'mic_enabled' => true,
                        'microphone_track_count' => 1,
                        'is_follow_up_turn' => true,
                        'is_contextual_follow_up_turn' => true,
                        'contextual_follow_up_kind' => $kind,
                        'turn_completed_ms' => 1110 + $index,
                    ],
                ],
            ]);
        }

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_followup_ready',
                'details' => [
                    'ready_elapsed_ms' => 12,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'turn_completed_ms' => 1110,
                ],
            ],
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'model' => 'gpt-realtime-test',
            'route_tier' => 'realtime',
            'request_type' => 'realtime_voice_event',
            'status' => 'completed',
            'metadata' => [
                'event_type' => 'flutter_realtime_audio_done_ready',
                'details' => [
                    'response_id' => 'resp_fast_2',
                    'ready_elapsed_ms' => 0,
                    'status' => 'listening',
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'transcription_only_release_pending' => false,
                    'background_work_active' => false,
                    'audio_elapsed_ms' => 1180,
                ],
            ],
        ]);

        $exitCode = Artisan::call('bean:production-smoke', [
            '--scenario' => 'voice-quality',
            '--email' => $email,
            '--voice-days' => 7,
            '--voice-min-turns' => 10,
            '--suite-id' => $suiteId,
        ]);

        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($summary['failed']);
        $this->assertSame([], $summary['failures']);
        $this->assertSame($suiteId, $summary['suite_id']);
        $this->assertSame($user->id, $summary['user_id']);
        $this->assertSame($workspace->id, $summary['workspace_id']);
        $this->assertSame('pass', data_get($summary, 'gate.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.latency.status'));
        $this->assertSame(700, data_get($summary, 'gate.requirements.latency.targets.p50_transcript_to_first_assistant_ms'));
        $this->assertSame(1200, data_get($summary, 'gate.requirements.latency.targets.p95_transcript_to_first_assistant_ms'));
        $this->assertSame(5000, data_get($summary, 'gate.requirements.latency.targets.p95_full_turn_ms'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.barge_in_interruption_recovery.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.fresh_context_accuracy.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.contextual_followups.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.natural_voice.status'));
        $this->assertSame('pass', data_get($summary, 'gate.requirements.live_session_reliability.status'));
        $this->assertSame('voice-quality', data_get($summary, 'verification.scenario'));
        $this->assertSame($email, data_get($summary, 'verification.email'));
        $this->assertSame(7, data_get($summary, 'verification.days'));
        $this->assertSame(10, data_get($summary, 'verification.minimum_turns'));
        $this->assertSame(10, data_get($summary, 'verification.turn_sample_size'));
        $this->assertSame(21, data_get($summary, 'verification.event_sample_size'));
        $this->assertSame(
            ['confirmation', 'decline', 'correction', 'continuation', 'reference'],
            data_get($summary, 'verification.required_micro_follow_up_kinds'),
        );
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_kind_counts.reference'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_ready_kind_counts.confirmation'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_ready_kind_counts.decline'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_ready_kind_counts.correction'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_ready_kind_counts.continuation'));
        $this->assertSame(1, data_get($summary, 'verification.observed_micro_follow_up_ready_kind_counts.reference'));
        $this->assertSame(5, data_get($summary, 'conversation.micro_follow_up_kind_count'));
        $this->assertSame(5, data_get($summary, 'events.follow_up_readiness_quality.micro_follow_up_ready_kind_count'));
        $this->assertSame(
            'php artisan bean:production-smoke --scenario=voice-quality --email=voice-quality-command@example.com --voice-days=7 --voice-min-turns=10 --suite-id=voice-quality-suite',
            data_get($summary, 'verification.rerun_command'),
        );
    }

    public function test_voice_quality_smoke_command_can_target_existing_user_and_workspace(): void
    {
        $suiteId = 'voice-quality-target-suite';
        $user = User::factory()->create(['email' => 'voice-quality-target@example.com']);
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);

        $exitCode = Artisan::call('bean:production-smoke', [
            '--scenario' => 'voice-quality',
            '--user-id' => $user->id,
            '--workspace-id' => $workspace->id,
            '--voice-days' => 7,
            '--voice-min-turns' => 1,
            '--suite-id' => $suiteId,
        ]);

        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($summary['failed']);
        $this->assertSame('no_data', $summary['status']);
        $this->assertSame($suiteId, $summary['suite_id']);
        $this->assertSame($user->id, $summary['user_id']);
        $this->assertSame($workspace->id, $summary['workspace_id']);
        $this->assertSame($user->email, data_get($summary, 'verification.email'));
        $this->assertSame(1, data_get($summary, 'verification.minimum_turns'));
        $this->assertSame(0, data_get($summary, 'verification.turn_sample_size'));
        $this->assertSame(0, data_get($summary, 'verification.event_sample_size'));
        $this->assertSame('no_data', data_get($summary, 'gate.status'));
        $this->assertSame(
            "php artisan bean:production-smoke --scenario=voice-quality --user-id={$user->id} --workspace-id={$workspace->id} --voice-days=7 --voice-min-turns=1 --suite-id={$suiteId}",
            data_get($summary, 'verification.rerun_command'),
        );
    }

    public function test_completed_run_is_not_ready_for_smoke_judgment_until_assistant_message_exists(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Smoke readiness session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Create a checklist note called Saturday Reset.',
        ]);
        $run = AssistantRun::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'source' => 'production_smoke',
            'status' => 'completed',
            'input' => $userMessage->content,
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'runIsReadyForSmokeJudgment');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command, $run->load('assistantMessage')));

        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Done - I created Saturday Reset.',
        ]);
        $run->update(['assistant_message_id' => $assistantMessage->id]);

        $this->assertTrue($method->invoke($command, $run->refresh()->load('assistantMessage')));
    }

    public function test_followup_state_checks_detect_duplicates_and_missing_items(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Followup smoke session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'title' => 'Workout',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'title' => 'Workout',
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'followupStateFailures');
        $method->setAccessible(true);

        $failures = $method->invoke($command, $session, [
            'assertions' => [
                'calendar_title_counts' => ['Workout' => 1],
                'minimum_reminder_title_contains_counts' => ['grocery' => 1],
            ],
        ]);

        $this->assertContains('calendar_count:Workout:2/1', $failures);
        $this->assertContains('reminder_min:grocery:0/1', $failures);
    }

    public function test_work_item_quality_checks_flag_bad_labels_and_missing_completion(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Smoke work item session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Create a task for later this afternoon to vacuum the house.',
        ]);
        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Done - I added vacuum the house to your tasks.',
        ]);
        $run = AssistantRun::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'source' => 'production_smoke',
            'status' => 'completed',
            'input' => $userMessage->content,
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.work_item.planned',
            'tool_name' => 'assistant.work',
            'status' => 'planned',
            'payload' => [
                'work_item_id' => 'crud-plan-'.$userMessage->id.'-0',
                'work_order' => 0,
                'label' => 'Creating task: i need to the rest of my day i want a workout',
                'message_id' => $userMessage->id,
                'user_message_id' => $userMessage->id,
            ],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'workItemQualityFailures');
        $method->setAccessible(true);

        $failures = $method->invoke($command, $run);

        $this->assertContains('work_item_bad_label:crud-plan-'.$userMessage->id.'-0:Creating task: i need to the rest of my day i want a workout', $failures);
        $this->assertContains('work_item_not_completed:crud-plan-'.$userMessage->id.'-0:Creating task: i need to the rest of my day i want a workout', $failures);
    }

    public function test_work_item_quality_checks_accept_clean_lifecycle(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Smoke work item session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Create a task to vacuum the house.',
        ]);
        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Done - I added vacuum the house to your tasks.',
        ]);
        $run = AssistantRun::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'source' => 'production_smoke',
            'status' => 'completed',
            'input' => $userMessage->content,
        ]);
        $workItemId = 'crud-plan-'.$userMessage->id.'-0';
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.work_item.planned',
            'tool_name' => 'assistant.work',
            'status' => 'planned',
            'payload' => [
                'work_item_id' => $workItemId,
                'work_order' => 0,
                'label' => 'Create task: Vacuum the house',
                'message_id' => $userMessage->id,
                'user_message_id' => $userMessage->id,
            ],
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.task.created',
            'tool_name' => 'tasks.create',
            'status' => 'succeeded',
            'payload' => [
                'task_id' => 123,
                'title' => 'Vacuum the house',
                'work_item_id' => $workItemId,
                'work_order' => 0,
                'work_label' => 'Create task: Vacuum the house',
                'message_id' => $userMessage->id,
                'user_message_id' => $userMessage->id,
            ],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'workItemQualityFailures');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($command, $run));
    }

    public function test_followup_state_checks_accept_expected_artifacts(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Followup smoke session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'title' => 'Workout',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'title' => 'Reminder: grocery shopping',
            'remind_at' => now()->addHour(),
        ]);
        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Egg Protein Note',
            'body_html' => 'Egg Protein Note',
            'plain_text' => 'Egg Protein Note',
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.note.created',
            'tool_name' => 'notes.create',
            'status' => 'succeeded',
            'payload' => ['note_id' => $note->id],
        ]);
        MemoryItem::create([
            'user_id' => $user->id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'content' => 'I prefer concise status updates for errands.',
            'source_type' => 'assistant_tool',
            'source_id' => $session->id,
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'followupStateFailures');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($command, $session, [
            'assertions' => [
                'calendar_title_counts' => ['Workout' => 1],
                'minimum_reminder_title_contains_counts' => ['grocery' => 1],
                'note_title_counts' => ['Egg Protein Note' => 1],
                'memory_contains' => ['concise status updates'],
            ],
        ]));
    }

    public function test_smoke_account_reset_clears_ai_usage_logs(): void
    {
        $user = User::factory()->create();
        AiUsageLog::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'gpt-test-tools',
            'route_tier' => 'agent',
            'request_type' => 'text',
            'status' => 'completed',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'estimated_cost_usd' => 0.01,
            'action_types' => ['calendar_event.create'],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'resetSmokeUserData');
        $method->setAccessible(true);
        $method->invoke($command, $user);

        $this->assertDatabaseMissing('ai_usage_logs', [
            'user_id' => $user->id,
        ]);
    }

    public function test_suite_cleanup_removes_all_suite_artifacts(): void
    {
        $user = User::factory()->create();
        $suiteId = 'test-suite-cleanup';
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Smoke cleanup session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'metadata' => ['suite_id' => $suiteId],
            'last_activity_at' => now(),
        ]);
        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Smoke note',
            'body_html' => 'Smoke note',
            'plain_text' => 'Smoke note',
            'metadata' => ['created_by' => 'structured_hermes_action'],
        ]);
        $memory = MemoryItem::create([
            'user_id' => $user->id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'content' => 'Smoke memory',
            'source_type' => 'assistant_tool',
            'source_id' => $session->id,
        ]);
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'remember this',
        ]);
        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Done.',
        ]);
        AssistantRun::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'source' => 'production_smoke',
            'status' => 'completed',
            'input' => 'remember this',
            'metadata' => ['suite_id' => $suiteId],
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.note.created',
            'tool_name' => 'notes.create',
            'status' => 'succeeded',
            'payload' => ['note_id' => $note->id],
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.memory.created',
            'tool_name' => 'memory.create',
            'status' => 'succeeded',
            'payload' => ['memory_item_id' => $memory->id],
        ]);
        MemoryEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'event_type' => 'turn_candidate',
            'status' => 'processed',
            'content' => 'remember this',
        ]);
        AiUsageLog::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'provider' => 'openai',
            'model' => 'gpt-test-tools',
            'route_tier' => 'agent',
            'request_type' => 'text',
            'status' => 'completed',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'estimated_cost_usd' => 0.01,
            'action_types' => ['memory.create'],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'cleanup');
        $method->setAccessible(true);
        $method->invoke($command, $user, $suiteId);

        $this->assertDatabaseMissing('conversation_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('conversation_messages', ['id' => $userMessage->id]);
        $this->assertDatabaseMissing('conversation_messages', ['id' => $assistantMessage->id]);
        $this->assertDatabaseMissing('assistant_runs', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('activity_events', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('memory_events', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('ai_usage_logs', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('memory_items', ['id' => $memory->id]);
    }
}
