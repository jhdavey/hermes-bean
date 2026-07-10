<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\HermesToolRuntimeService;
use App\Services\LiveLookupService;
use App\Services\OpenMeteoWeatherService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class HermesToolRuntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.fast_chat_model', 'gpt-test-fast');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.hermes_runtime.crud_planner_enabled', false);
        config()->set('services.hermes_runtime.tavily_api_key', '');
        config()->set('services.hermes_runtime.google_maps_api_key', '');
        Cache::flush();
    }

    public function test_tool_runtime_sends_user_prompt_directly_and_exposes_native_tools(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-direct',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I can help with that.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-direct@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->assertJsonPath('data.runtime_mode', 'tools')
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'look up the current weather and add a task to review it tonight',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I can help with that.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_started'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $messages = collect($payload['messages'] ?? []);
            $systemPrompt = (string) data_get($messages->firstWhere('role', 'system'), 'content');
            $contextMessage = $messages->first(fn (mixed $message): bool => is_array($message)
                && ($message['role'] ?? null) === 'system'
                && str_starts_with((string) ($message['content'] ?? ''), "Runtime context:\n"));
            if (! is_array($contextMessage)) {
                return false;
            }
            $context = json_decode(str_replace("Runtime context:\n", '', (string) $contextMessage['content']), true, flags: JSON_THROW_ON_ERROR);
            $toolNames = collect($payload['tools'] ?? [])->map(fn (array $tool): ?string => data_get($tool, 'function.name'));

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && str_contains($systemPrompt, 'Do not run a first-login onboarding interview in normal chat')
                && str_contains($systemPrompt, 'guided signup collects account and Bean preferences')
                && str_contains($systemPrompt, 'If the user explicitly asks to change Bean preferences later')
                && str_contains($systemPrompt, 'use get_day_context rather than request history')
                && $messages->contains(fn (mixed $message): bool => is_array($message)
                    && ($message['role'] ?? null) === 'user'
                    && ($message['content'] ?? null) === 'look up the current weather and add a task to review it tonight')
                && ! array_key_exists('dashboard_state', $context)
                && isset($context['temporal_context'], $context['workspace'], $context['agent_profile'])
                && $toolNames->contains('search_tasks')
                && $toolNames->contains('external_lookup')
                && $toolNames->contains('update_task')
                && ! $toolNames->contains('create_approval');
        });
    }

    public function test_tool_runtime_replaces_legacy_hard_error_copy(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-hard-error-copy',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Bean could not finish that request.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-hard-error-copy@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you check this?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $content = (string) $response->json('data.assistant_message.content');
        $this->assertStringNotContainsString('Bean could not finish', $content);
        $this->assertStringContainsString('I’m on it', $content);
    }

    public function test_capability_question_does_not_execute_write_tool_call(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-capability-fast',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Yes - I can create calendar events when you give me the details.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-capability-question@example.com');
        $user = User::where('email', 'tool-capability-question@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-001: Can you create calendar events?',
            'metadata' => [
                'source' => 'web',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Yes - I can create calendar events when you give me the details.')
            ->assertJsonFragment(['lane' => 'simple_question'])
            ->assertJsonFragment(['event_type' => 'runtime.fast_response_completed']);

        $this->assertDatabaseMissing('calendar_events', [
            'user_id' => $user->id,
            'title' => 'New calendar event',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && data_get($payload, 'model') === 'gpt-test-fast'
                && ! array_key_exists('tools', $payload)
                && str_contains((string) data_get($payload, 'messages.0.content'), 'no tools');
        });
    }

    public function test_common_general_bean_questions_use_fast_no_tools_lane(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-general-fast-1',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'I can help manage your day and answer quick questions.'],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-general-fast-2',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'A task is something to finish; a reminder is a timed nudge.'],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-general-fast-3',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Use Bean for quick planning and simple day context.'],
                ]],
            ], 200);

        $token = $this->apiToken('tool-general-fast-path@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $expectations = [
            [
                'content' => 'What can you help me manage in HeyBean?',
                'contains' => 'help manage your day',
            ],
            [
                'content' => 'What is the difference between a task and a reminder?',
                'contains' => 'A task is something to finish',
            ],
            [
                'content' => 'How should I think about using Bean for my day?',
                'contains' => 'Use Bean for quick planning',
            ],
        ];

        foreach ($expectations as $expectation) {
            $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
                'content' => $expectation['content'],
                'metadata' => ['source' => 'web'],
            ])->assertCreated()
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonFragment(['event_type' => 'runtime.fast_response_completed']);

            $this->assertStringContainsString(
                $expectation['contains'],
                (string) $response->json('data.assistant_message.content')
            );
        }

        Http::assertSentCount(3);
    }

    public function test_post_action_conversational_acknowledgements_use_fast_no_tools_lane(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-ack-fast-1',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Glad that helped.'],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-ack-fast-2',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Happy to help.'],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-ack-fast-3',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'You are welcome.'],
                ]],
            ], 200);

        $token = $this->apiToken('tool-conversation-ack@example.com');
        $user = User::where('email', 'tool-conversation-ack@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Write a three paragraph essay in a note.',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Done - I created the note.',
        ]);

        $expectations = [
            ['content' => 'That’s awesome, thanks', 'reply' => 'Glad that helped.'],
            ['content' => 'That’s awesome, thanks for writing that note', 'reply' => 'Happy to help.'],
            ['content' => 'thanks for writing that note', 'reply' => 'You are welcome.'],
        ];

        foreach ($expectations as $expectation) {
            $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
                'content' => $expectation['content'],
                'metadata' => ['source' => 'web'],
            ])->assertCreated()
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.assistant_message.content', $expectation['reply'])
                ->assertJsonFragment(['lane' => 'simple_conversation'])
                ->assertJsonFragment(['event_type' => 'runtime.fast_response_completed']);
        }

        Http::assertSentCount(3);
    }

    public function test_conversational_acknowledgement_with_new_request_does_not_use_ack_fast_path(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-thanks-new-request',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I can help with that note.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-conversation-ack-new-request@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'thanks, can you create another note',
            'metadata' => ['source' => 'web'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I can help with that note.');

        Http::assertSentCount(1);
    }

    public function test_routed_runs_complete_simple_turns_and_queue_app_work_by_lane(): void
    {
        Queue::fake();

        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-routed-simple',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'That sounds good.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-routed-runs@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'sounds good',
            'source' => 'web_routed_chat',
            'metadata' => ['client_request_id' => 'simple-1'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.assistant_message.content', 'That sounds good.')
            ->assertJsonPath('data.intent.lane', 'simple_conversation')
            ->assertJsonFragment(['event_type' => 'runtime.fast_response_completed']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'create a note called Follow up with one sentence',
            'source' => 'web_routed_chat',
            'metadata' => ['client_request_id' => 'write-1'],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.intent.lane', 'needs_app_write')
            ->assertJsonFragment(['event_type' => 'runtime.intent_routed'])
            ->assertJsonFragment(['event_type' => 'assistant.work_item.planned'])
            ->assertJsonFragment(['work_label' => 'Update notes']);

        Http::assertSentCount(1);
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_stale_prior_completion_claim_cannot_skip_requested_app_write(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-stale-already-done',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I already created that note for you.',
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-create-note-after-correction',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_create_note',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_note',
                                'arguments' => json_encode([
                                    'title' => 'Directions to boil an egg',
                                    'plain_text' => "1. Bring water to a boil.\n2. Add the egg.\n3. Cook 9-12 minutes.",
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-create-note-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done — I created the note.',
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-stale-already@example.com');
        $user = User::where('email', 'tool-stale-already@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'create a note with directions to boil an egg',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'All set — I created the note.',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'create a note with directions to boil an egg',
            'metadata' => [
                'source' => 'web',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I created Directions to boil an egg.')
            ->assertJsonFragment(['event_type' => 'assistant.note.created']);

        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'title' => 'Directions to boil an egg',
        ]);

        Http::assertSent(function ($request): bool {
            $messages = collect($request->data()['messages'] ?? []);

            return $messages->contains(fn (mixed $message): bool => is_array($message)
                && ($message['role'] ?? null) === 'system'
                && str_contains((string) ($message['content'] ?? ''), 'claimed prior completion without checking current app state'));
        });
    }

    public function test_crud_planner_creates_shopping_list_from_previous_assistant_response_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake();

        $token = $this->apiToken('tool-shopping-list-follow-up@example.com');
        $user = User::where('email', 'tool-shopping-list-follow-up@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => "Tomorrow dinner is lemon chicken sheet-pan bowls.\n\nShopping list:\n- Chicken thighs\n- Lemons\n- Rice\n- Green beans\n- Olive oil\n- Garlic",
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a shopping list based on that previous response.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.metadata.planner_source', 'local')
            ->assertJsonFragment(['event_type' => 'assistant.note.created']);

        $note = Note::where('user_id', $user->id)->where('title', 'Shopping list')->firstOrFail();
        $this->assertStringContainsString('- Chicken thighs', $note->plain_text);
        $this->assertStringContainsString('- Green beans', $note->plain_text);

        Http::assertSentCount(0);
    }

    public function test_tool_runtime_emits_ordered_work_items_for_write_tool_calls(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-work-plan-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_event',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Deep work',
                                        'starts_at' => '2026-06-24T20:00:00-04:00',
                                        'ends_at' => '2026-06-24T23:00:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_reminder',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_reminder',
                                    'arguments' => json_encode([
                                        'title' => 'Reminder: Deep work',
                                        'remind_at' => '2026-06-24T19:30:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-work-plan-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done — I added the deep work block and reminder.',
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-work-plan@example.com');
        $user = User::where('email', 'tool-work-plan@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'I need to do some deep work later. Put a work block on my schedule for 8-11pm. Set a reminder 30 minutes before as well',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-24T17:37:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.work_item.planned'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created']);

        $events = collect($response->json('data.events'));
        $planned = $events->where('event_type', 'assistant.work_item.planned')->values();
        $this->assertSame('Create calendar event: Deep work', data_get($planned[0], 'payload.label'));
        $this->assertSame(0, data_get($planned[0], 'payload.work_order'));
        $this->assertSame('Create reminder: Deep work', data_get($planned[1], 'payload.label'));
        $this->assertSame(1, data_get($planned[1], 'payload.work_order'));

        $calendarEvent = $events->firstWhere('event_type', 'assistant.calendar_event.created');
        $reminderEvent = $events->firstWhere('event_type', 'assistant.reminder.created');
        $this->assertSame(data_get($planned[0], 'payload.work_item_id'), data_get($calendarEvent, 'payload.work_item_id'));
        $this->assertSame('Create calendar event: Deep work', data_get($calendarEvent, 'payload.work_label'));
        $this->assertSame(data_get($planned[1], 'payload.work_item_id'), data_get($reminderEvent, 'payload.work_item_id'));
        $this->assertSame('Create reminder: Deep work', data_get($reminderEvent, 'payload.work_label'));

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'title' => 'Deep work',
        ]);
        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Reminder: Deep work',
        ]);
    }

    public function test_tool_runtime_treats_web_utc_wall_clock_tool_times_as_client_local(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-web-wall-clock-tools',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_workout_event',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Workout',
                                        'starts_at' => '2026-07-08T05:00:00Z',
                                        'ends_at' => '2026-07-08T06:00:00Z',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_workout_reminder',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_reminder',
                                    'arguments' => json_encode([
                                        'title' => 'Workout',
                                        'remind_at' => '2026-07-08T09:00:00Z',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-web-wall-clock-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done — I added the workout and reminder.',
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-web-wall-clock@example.com');
        $user = User::where('email', 'tool-web-wall-clock@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add workout to my calendar from 5-6am and remind me tomorrow morning.',
            'metadata' => [
                'source' => 'web_routed_chat',
                'client_context' => [
                    'current_local_time' => '2026-07-07T22:13:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $event = CalendarEvent::query()
            ->where('user_id', $user->id)
            ->where('title', 'Workout')
            ->firstOrFail();
        $reminder = Reminder::query()
            ->where('user_id', $user->id)
            ->where('title', 'Workout')
            ->firstOrFail();

        $this->assertSame('2026-07-08 09:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-08 10:00:00', $event->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-08 13:00:00', $reminder->remind_at->format('Y-m-d H:i:s'));
    }

    public function test_tool_runtime_keeps_work_item_order_across_separate_tool_turns(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-work-plan-turn-one',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_event_turn_one',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_calendar_event',
                                'arguments' => json_encode([
                                    'title' => 'Focus block',
                                    'starts_at' => '2026-06-24T09:00:00-04:00',
                                    'ends_at' => '2026-06-24T10:00:00-04:00',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-work-plan-turn-two',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_task_turn_two',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode([
                                    'title' => 'Prep agenda',
                                    'due_at' => '2026-06-24T08:00:00-04:00',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-work-plan-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done — I added the focus block and agenda task.',
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-work-plan-turns@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Set up a focus block and a prep agenda task for tomorrow morning',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-23T17:37:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $planned = collect($response->json('data.events'))
            ->where('event_type', 'assistant.work_item.planned')
            ->values();

        $this->assertCount(2, $planned);
        $this->assertSame('Create calendar event: Focus block', data_get($planned[0], 'payload.label'));
        $this->assertSame(0, data_get($planned[0], 'payload.work_order'));
        $this->assertSame('Create task: Prep agenda', data_get($planned[1], 'payload.label'));
        $this->assertSame(1, data_get($planned[1], 'payload.work_order'));
    }

    public function test_tool_runtime_saves_onboarding_profile_from_top_level_tool_fields(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-onboarding-tool',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_profile',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_agent_profile',
                                'arguments' => json_encode([
                                    'name' => 'Harley',
                                    'city' => 'Orlando',
                                    'what_matters' => 'Family, reminders, and planning',
                                    'personality_type' => 'coach',
                                    'completed' => true,
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-onboarding-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'All set, Harley.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('onboarding-tool@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'onboarding',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => "Hi, I'm Harley",
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I updated Bean settings.');

        $user = User::where('email', 'onboarding-tool@example.com')->firstOrFail();
        $profile = $user->agentProfile()->firstOrFail();

        $this->assertTrue($user->refresh()->onboard_complete);
        $this->assertSame('coach', $profile->settings['personality_type']);
        $this->assertSame('Harley', data_get($profile->settings, 'onboarding.name'));
        $this->assertSame('Orlando', data_get($profile->settings, 'onboarding.city'));
        $this->assertTrue(data_get($profile->settings, 'onboarding.completed'));
        $this->assertSame(['Family, reminders, and planning'], data_get($profile->settings, 'onboarding.priorities'));
    }

    public function test_tool_runtime_does_not_store_skipped_onboarding_location(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-onboarding-skip-location-tool',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_profile',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_agent_profile',
                                'arguments' => json_encode([
                                    'settings' => [
                                        'personality_type' => 'gentle',
                                        'onboarding' => [
                                            'completed' => true,
                                            'name' => 'Harley',
                                            'city' => 'skip',
                                            'priorities' => ['Family and planning'],
                                            'context' => 'Family and planning matter most day to day.',
                                        ],
                                    ],
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-onboarding-skip-location-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'All set — I will keep your location private.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('onboarding-skip-location@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'onboarding',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'skip',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I updated Bean settings.');

        $user = User::where('email', 'onboarding-skip-location@example.com')->firstOrFail();
        $profile = $user->agentProfile()->firstOrFail();

        $this->assertTrue($user->refresh()->onboard_complete);
        $this->assertSame('gentle', $profile->settings['personality_type']);
        $this->assertSame('Harley', data_get($profile->settings, 'onboarding.name'));
        $this->assertNull(data_get($profile->settings, 'onboarding.city'));
        $this->assertNull(data_get($profile->settings, 'onboarding.location'));
        $this->assertTrue(data_get($profile->settings, 'onboarding.completed'));
    }

    public function test_tool_runtime_sends_recent_conversation_history_for_followups(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-history',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check tasks for take out the trash.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-history@example.com');
        $user = User::where('email', 'tool-history@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'what am I supposed to take out the trash',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'I do not see a trash reminder.',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'it should be a task not a reminder',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'I will check tasks for take out the trash.');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $prompt = (string) data_get($payload, 'messages.0.content');

            return str_contains($prompt, 'recent conversation turns')
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'what am I supposed to take out the trash'
                && data_get($payload, 'messages.3.role') === 'assistant'
                && data_get($payload, 'messages.3.content') === 'I do not see a trash reminder.'
                && data_get($payload, 'messages.4.role') === 'user'
                && data_get($payload, 'messages.4.content') === 'it should be a task not a reminder';
        });
    }

    public function test_request_history_tool_searches_message_content_without_missing_columns(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected-history-fast-path',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('tool-request-history-query@example.com');
        $user = User::where('email', 'tool-request-history-query@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'REQ-100: What remains today?',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What did I ask for REQ-100?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $content = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('You asked:', $content);
        $this->assertStringContainsString('REQ-100: What remains today?', $content);

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_native_read_tools_return_client_visible_dates_for_calendar_tasks_and_reminders(): void
    {
        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() !== 'https://api.openai.test/v1/chat/completions') {
                return Http::response(['error' => 'Unexpected request'], 500);
            }

            $chatCalls++;
            if ($chatCalls === 1) {
                return Http::response([
                    'id' => 'chatcmpl-read-tools',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_calendar',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'search_calendar_events',
                                        'arguments' => json_encode(['query' => 'Timed multi-day event'], JSON_THROW_ON_ERROR),
                                    ],
                                ],
                                [
                                    'id' => 'call_tasks',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'search_tasks',
                                        'arguments' => json_encode(['from_date' => '2026-06-08', 'to_date' => '2026-06-08'], JSON_THROW_ON_ERROR),
                                    ],
                                ],
                                [
                                    'id' => 'call_reminders',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'search_reminders',
                                        'arguments' => json_encode(['from_date' => '2026-06-08', 'to_date' => '2026-06-08'], JSON_THROW_ON_ERROR),
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ], 200);
            }

            $toolOutputs = collect($request->data()['messages'] ?? [])
                ->where('role', 'tool')
                ->mapWithKeys(function (array $message): array {
                    $output = json_decode((string) data_get($message, 'content'), true, flags: JSON_THROW_ON_ERROR);

                    return [(string) data_get($output, 'tool') => $output];
                });

            $calendar = $toolOutputs->get('search_calendar_events');
            $task = $toolOutputs->get('search_tasks');
            $reminder = $toolOutputs->get('search_reminders');

            $this->assertSame('America/New_York', data_get($calendar, 'timezone'));
            $this->assertSame('2026-06-05T13:00:00-04:00', data_get($calendar, 'items.0.starts_at'));
            $this->assertSame('2026-06-08T20:00:00-04:00', data_get($calendar, 'items.0.ends_at'));
            $this->assertSame('2026-06-09T00:00:00+00:00', data_get($calendar, 'items.0.ends_at_utc'));
            $this->assertSame('2026-06-08', data_get($calendar, 'items.0.display_end_date'));
            $this->assertSame('2026-06-05 through 2026-06-08', data_get($calendar, 'items.0.display_date_range'));
            $this->assertSame('2026-06-08T20:00:00-04:00', data_get($task, 'items.0.due_at'));
            $this->assertSame('2026-06-08', data_get($task, 'items.0.display_due_date'));
            $this->assertSame('2026-06-08T20:00:00-04:00', data_get($reminder, 'items.0.remind_at'));
            $this->assertSame('2026-06-08', data_get($reminder, 'items.0.display_remind_date'));

            return Http::response([
                'id' => 'chatcmpl-read-tools-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'That event covers June 5 through June 8.',
                    ],
                ]],
            ], 200);
        });

        $token = $this->apiToken('tool-read-timezone@example.com');
        $user = User::where('email', 'tool-read-timezone@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Timed multi-day event',
            'starts_at' => Carbon::parse('2026-06-05 13:00:00', 'America/New_York')->utc(),
            'ends_at' => Carbon::parse('2026-06-08 20:00:00', 'America/New_York')->utc(),
            'status' => 'confirmed',
        ]);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Pack bags',
            'status' => 'pending',
            'due_at' => Carbon::parse('2026-06-08 20:00:00', 'America/New_York')->utc(),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Check in online',
            'status' => 'pending',
            'remind_at' => Carbon::parse('2026-06-08 20:00:00', 'America/New_York')->utc(),
        ]);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What dates does my upcoming multi-day event cover, and what else is due that day?',
            'metadata' => [
                'client_context' => [
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'That event covers June 5 through June 8.');

        $this->assertSame(2, $chatCalls);
    }

    public function test_tool_runtime_executes_native_task_update_tool_call(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_task',
                                'arguments' => json_encode([
                                    'match_title' => 'Litter Box',
                                    'status' => 'completed',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Marked Litter Box complete.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-update@example.com');
        $user = User::where('email', 'tool-update@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $family = app(WorkspaceService::class)->createHousehold($user, 'Family');
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Litter Box',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $familyCopy = app(WorkspaceItemSyncService::class)->sync($task, $family, $user);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'mark litter box complete',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I updated Litter Box.')
            ->assertJsonFragment(['event_type' => 'assistant.task.updated']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Litter Box',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $familyCopy->id,
            'workspace_id' => $family->id,
            'title' => 'Litter Box',
            'status' => 'completed',
        ]);
        $this->assertNotNull(Task::findOrFail($task->id)->completed_at);
        $this->assertNotNull(Task::findOrFail($familyCopy->id)->completed_at);

        Http::assertSentCount(1);
    }

    public function test_tool_runtime_falls_back_when_final_assistant_json_envelope_is_blank(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-note-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_note',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_note',
                                'arguments' => json_encode([
                                    'title' => 'Directions to boil an egg',
                                    'body' => "1. Bring water to a boil.\n2. Add the egg.\n3. Cook, cool, and peel.",
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-note-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'role' => 'assistant',
                            'content' => "\n",
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-note-envelope@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'create a note with directions to boil an egg',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I created Directions to boil an egg.');

        $this->assertDatabaseHas('notes', [
            'title' => 'Directions to boil an egg',
        ]);
        $this->assertSame(1, Note::where('title', 'Directions to boil an egg')->count());
    }

    public function test_tool_runtime_executes_external_lookup_tool_call(): void
    {
        config()->set('services.hermes_runtime.external_lookup_model', 'gpt-lookup-test');
        config()->set('services.hermes_runtime.osm_places_enabled', false);

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-external-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_lookup',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'Ace Hardware Orlando closing time today',
                                            'context' => 'User wants to know when their local Ace Hardware closes today.',
                                            'location' => 'Orlando, FL',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response(['error' => 'Unexpected second chat completion request'], 500);
            }

            if ($request->url() === 'https://api.openai.test/v1/responses') {
                return Http::response([
                    'id' => 'resp_lookup',
                    'model' => 'gpt-lookup-test',
                    'output_text' => 'The local Ace Hardware closes at 8 PM today.',
                    'output' => [[
                        'type' => 'message',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'The local Ace Hardware closes at 8 PM today.',
                            'annotations' => [[
                                'type' => 'url_citation',
                                'url' => 'https://www.acehardware.com/store-details/example',
                                'title' => 'Ace Hardware store details',
                            ]],
                        ]],
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $token = $this->apiToken('tool-external@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'can you tell me when my local Ace Hardware closes today',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'The local Ace Hardware closes at 8 PM today.');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($payload, 'model') === 'gpt-lookup-test'
                && data_get($payload, 'tools.0.type') === 'web_search'
                && str_contains((string) data_get($payload, 'instructions'), 'concise live lookup helper')
                && str_contains((string) data_get($payload, 'input'), 'Ace Hardware Orlando closing time today')
                && str_contains((string) data_get($payload, 'input'), 'Orlando, FL');
        });

        $this->assertSame(1, $chatCalls);
        Http::assertSentCount(2);
    }

    public function test_external_lookup_routes_current_weather_to_open_meteo(): void
    {
        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    $toolNames = collect($request->data()['tools'] ?? [])
                        ->map(fn (array $tool): ?string => data_get($tool, 'function.name'))
                        ->filter()
                        ->values();
                    Assert::assertTrue($toolNames->contains('external_lookup'));
                    Assert::assertTrue($toolNames->contains('get_day_context'));
                    Assert::assertFalse($toolNames->contains('create_calendar_event'));
                    Assert::assertFalse($toolNames->contains('create_task'));

                    return Http::response([
                        'id' => 'chatcmpl-weather-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'current weather in Orlando Florida right now',
                                            'domain' => 'weather',
                                            'intent' => 'current_weather',
                                            'location' => 'Orlando, FL',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);

                $this->assertSame('open_meteo', data_get($lookupResult, 'provider'));
                $this->assertSame('weather_current', data_get($lookupResult, 'kind'));
                $this->assertSame('Orlando, Florida, US', data_get($lookupResult, 'location'));
                $this->assertSame(82.0, data_get($lookupResult, 'weather.temperature_f'));
                $this->assertSame('partly cloudy', data_get($lookupResult, 'weather.description'));

                return Http::response([
                    'id' => 'chatcmpl-weather-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($lookupResult, 'text'),
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response([
                    'results' => [[
                        'id' => 4167147,
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                return Http::response([
                    'timezone' => 'America/New_York',
                    'current' => [
                        'time' => '2026-06-02T02:30',
                        'temperature_2m' => 82.4,
                        'relative_humidity_2m' => 68,
                        'apparent_temperature' => 84.8,
                        'precipitation' => 0,
                        'weather_code' => 2,
                        'cloud_cover' => 45,
                        'wind_speed_10m' => 9.2,
                        'wind_direction_10m' => 90,
                        'wind_gusts_10m' => 14.1,
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-weather-open-meteo@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', "It's 82°F and partly cloudy in Orlando, Florida, US right now. Feels like 85°F, humidity is 68%, wind is 9 mph from the east.");

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertSentCount(2);
    }

    public function test_direct_weather_parser_separates_location_date_and_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:30:00', 'America/New_York'));

        $token = $this->apiToken('tool-weather-time-parser@example.com');
        $user = User::where('email', 'tool-weather-time-parser@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $message = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "Okay, and what's the weather in Orlando at 5 p.m. today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);

        $service = app(HermesToolRuntimeService::class);
        $method = (new \ReflectionClass($service))->getMethod('directExternalLookupArguments');
        $method->setAccessible(true);
        $arguments = $method->invoke($service, $session, $message);

        $this->assertSame('weather', data_get($arguments, 'domain'));
        $this->assertSame('weather_forecast', data_get($arguments, 'intent'));
        $this->assertSame('Orlando', data_get($arguments, 'location'));
        $this->assertSame('2026-07-10', data_get($arguments, 'date'));
        $this->assertSame('17:00', data_get($arguments, 'time'));

        $timeBeforeLocation = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather at 5 p.m. today in Orlando?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $timeBeforeLocationArguments = $method->invoke($service, $session, $timeBeforeLocation);

        $this->assertSame('Orlando', data_get($timeBeforeLocationArguments, 'location'));
        $this->assertSame('2026-07-10', data_get($timeBeforeLocationArguments, 'date'));
        $this->assertSame('17:00', data_get($timeBeforeLocationArguments, 'time'));

        $spokenTime = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at five thirty p.m. today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $spokenTimeArguments = $method->invoke($service, $session, $spokenTime);
        $this->assertSame('Orlando', data_get($spokenTimeArguments, 'location'));
        $this->assertSame('17:30', data_get($spokenTimeArguments, 'time'));

        $halfPastTime = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at half past five p.m. today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $halfPastTimeArguments = $method->invoke($service, $session, $halfPastTime);
        $this->assertSame('Orlando', data_get($halfPastTimeArguments, 'location'));
        $this->assertSame('17:30', data_get($halfPastTimeArguments, 'time'));

        $dayPartTime = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 5 o'clock this afternoon?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $dayPartTimeArguments = $method->invoke($service, $session, $dayPartTime);
        $this->assertSame('Orlando', data_get($dayPartTimeArguments, 'location'));
        $this->assertSame('17:00', data_get($dayPartTimeArguments, 'time'));

        $compactTime = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 1700 today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $compactTimeArguments = $method->invoke($service, $session, $compactTime);
        $this->assertSame('Orlando', data_get($compactTimeArguments, 'location'));
        $this->assertSame('17:00', data_get($compactTimeArguments, 'time'));

        $midnightTonight = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 12 tonight?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $midnightTonightArguments = $method->invoke($service, $session, $midnightTonight);
        $this->assertSame('00:00', data_get($midnightTonightArguments, 'time'));
        $this->assertSame('2026-07-11', data_get($midnightTonightArguments, 'date'));

        $oneTonight = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 1 tonight?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $oneTonightArguments = $method->invoke($service, $session, $oneTonight);
        $this->assertSame('01:00', data_get($oneTonightArguments, 'time'));
        $this->assertSame('2026-07-11', data_get($oneTonightArguments, 'date'));

        $fiveTonight = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 5 tonight?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $fiveTonightArguments = $method->invoke($service, $session, $fiveTonight);
        $this->assertSame('17:00', data_get($fiveTonightArguments, 'time'));
        $this->assertSame('2026-07-10', data_get($fiveTonightArguments, 'date'));

        $ambiguousBareHour = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 5 today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $this->assertNull($method->invoke($service, $session, $ambiguousBareHour));

        $namedDate = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 5 p.m. on Monday?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $namedDateArguments = $method->invoke($service, $session, $namedDate);
        $this->assertSame('Orlando', data_get($namedDateArguments, 'location'));
        $this->assertSame('2026-07-13', data_get($namedDateArguments, 'date'));
        $this->assertSame('17:00', data_get($namedDateArguments, 'time'));

        $monthDate = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's the weather in Orlando at 5 p.m. on July 14th?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);
        $monthDateArguments = $method->invoke($service, $session, $monthDate);
        $this->assertSame('Orlando', data_get($monthDateArguments, 'location'));
        $this->assertSame('2026-07-14', data_get($monthDateArguments, 'date'));
        $this->assertSame('17:00', data_get($monthDateArguments, 'time'));

        $locationMethod = (new \ReflectionClass($service))->getMethod('directWeatherLocation');
        $locationMethod->setAccessible(true);
        $this->assertSame('Orlando', $locationMethod->invoke($service, 'Weather in Orlando later in the afternoon'));
        $this->assertSame('Orlando', $locationMethod->invoke($service, 'Weather in Orlando in July'));

        $vagueTime = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Weather in Orlando later in the afternoon',
        ]);
        $this->assertNull($method->invoke($service, $session, $vagueTime));

        Carbon::setTestNow();
    }

    public function test_hourly_weather_rejects_invalid_explicit_time_instead_of_degrading_to_daily(): void
    {
        Http::fake();

        $result = app(OpenMeteoWeatherService::class)->weatherForIntent([
            'query' => 'What is the weather in Orlando at 17:65 today?',
            'domain' => 'weather',
            'intent' => 'weather_forecast',
            'location' => 'Orlando',
        ], 'America/New_York');

        $this->assertFalse(data_get($result, 'ok'));
        $this->assertSame('weather_hourly_forecast', data_get($result, 'kind'));
        $this->assertSame('weather_hourly_datetime_invalid', data_get($result, 'error_code'));
        $this->assertStringContainsString('specific time', (string) data_get($result, 'message'));
        Http::assertNothingSent();
    }

    public function test_hourly_weather_relative_date_label_uses_the_request_display_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 00:30:00', 'UTC'));
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response([
                    'results' => [[
                        'name' => 'Los Angeles',
                        'latitude' => 34.0522,
                        'longitude' => -118.2437,
                        'admin1' => 'California',
                        'country_code' => 'US',
                    ]],
                ]);
            }

            return Http::response([
                'timezone' => 'America/Los_Angeles',
                'hourly' => [
                    'time' => ['2026-07-10T17:00'],
                    'temperature_2m' => [78.0],
                    'weather_code' => [0],
                    'precipitation_probability' => [0],
                    'wind_speed_10m' => [6.0],
                ],
            ]);
        });

        $result = app(OpenMeteoWeatherService::class)->hourlyForecast(
            'Los Angeles',
            '2026-07-10',
            '17:00',
            ['timezone' => 'America/Los_Angeles'],
        );

        $this->assertTrue(data_get($result, 'ok'), json_encode($result, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('5 PM today', (string) data_get($result, 'text'));

        Carbon::setTestNow();
    }

    public function test_hourly_weather_labels_nearest_hour_without_claiming_an_exact_half_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:30:00', 'America/New_York'));

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response([
                    'results' => [[
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ]);
            }

            return Http::response([
                'timezone' => 'America/New_York',
                'hourly' => [
                    'time' => ['2026-07-10T17:00', '2026-07-10T18:00'],
                    'temperature_2m' => [86.6, 85.2],
                    'weather_code' => [2, 80],
                    'precipitation_probability' => [35, 45],
                    'wind_speed_10m' => [8.4, 9.8],
                ],
            ]);
        });

        $result = app(OpenMeteoWeatherService::class)->weatherForIntent([
            'query' => 'What is the weather in Orlando at five thirty p.m. today?',
            'domain' => 'weather',
            'intent' => 'weather_forecast',
        ], 'America/New_York', ['timezone' => 'America/New_York']);

        $this->assertTrue(data_get($result, 'ok'), json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame('17:30', data_get($result, 'weather.requested_time'));
        $this->assertSame('17:00', data_get($result, 'weather.matched_time'));
        $this->assertFalse(data_get($result, 'weather.is_exact_time'));
        $this->assertStringContainsString('Around 5:30 PM today', (string) data_get($result, 'text'));
        $this->assertStringContainsString('nearest hourly forecast (5 PM)', (string) data_get($result, 'text'));

        Carbon::setTestNow();
    }

    public function test_external_lookup_routes_specific_time_weather_to_open_meteo_hourly_forecast(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:30:00', 'America/New_York'));

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                $this->assertStringContainsString('name=Orlando', urldecode($request->url()));
                $this->assertStringNotContainsString('5+p.m', urldecode($request->url()));

                return Http::response([
                    'results' => [[
                        'id' => 4167147,
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                $payload = $request->data();
                $this->assertSame('2026-07-10', data_get($payload, 'start_date'));
                $this->assertSame('2026-07-10', data_get($payload, 'end_date'));
                $this->assertSame('auto', data_get($payload, 'timezone'));
                $this->assertSame(
                    'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation_probability,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    data_get($payload, 'hourly')
                );
                $this->assertNull(data_get($payload, 'current'));
                $this->assertNull(data_get($payload, 'daily'));

                return Http::response([
                    'timezone' => 'America/New_York',
                    'hourly' => [
                        'time' => ['2026-07-10T16:00', '2026-07-10T17:00', '2026-07-10T18:00'],
                        'temperature_2m' => [88.1, 86.6, 85.2],
                        'apparent_temperature' => [93.2, 91.1, 89.3],
                        'relative_humidity_2m' => [68, 70, 72],
                        'precipitation_probability' => [25, 35, 45],
                        'precipitation' => [0, 0.02, 0.08],
                        'weather_code' => [1, 2, 80],
                        'cloud_cover' => [30, 48, 70],
                        'wind_speed_10m' => [7.1, 8.4, 9.8],
                        'wind_direction_10m' => [100, 110, 120],
                        'wind_gusts_10m' => [12.2, 14.1, 16.3],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-weather-hourly@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => "Okay, and what's the weather in Orlando at 5 p.m. today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath(
                'data.assistant_message.content',
                'At 5 PM today in Orlando, Florida, US, expect 87°F and partly cloudy, with a 35% chance of precipitation and winds around 8 mph.'
            );

        $lookupResult = app(LiveLookupService::class)->lookup(ConversationSession::findOrFail($sessionId), [
            'query' => "Okay, and what's the weather in Orlando at 5 p.m. today?",
            'domain' => 'weather',
            'intent' => 'weather_forecast',
            'location' => 'Orlando',
            'date' => '2026-07-10',
            'time' => '17:00',
        ]);
        $this->assertSame('weather_hourly_forecast', data_get($lookupResult, 'kind'));
        $this->assertSame('2026-07-10', data_get($lookupResult, 'date'));
        $this->assertSame('17:00', data_get($lookupResult, 'time'));
        $this->assertSame('2026-07-10T17:00', data_get($lookupResult, 'weather.time'));
        $this->assertSame(87.0, data_get($lookupResult, 'weather.temperature_f'));
        $this->assertSame(35.0, data_get($lookupResult, 'weather.precipitation_probability_percent'));
        $this->assertSame('partly cloudy', data_get($lookupResult, 'weather.description'));

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertSentCount(2);
        Carbon::setTestNow();
    }

    public function test_structured_weather_provider_failure_does_not_fall_through_to_generic_search(): void
    {
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response(['reason' => 'provider unavailable'], 503);
            }

            return Http::response(['error' => 'Unexpected fallback request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-weather-safe-failure@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $result = app(LiveLookupService::class)->lookup(ConversationSession::findOrFail($sessionId), [
            'query' => 'current weather in Orlando',
            'domain' => 'weather',
            'intent' => 'current_weather',
            'location' => 'Orlando',
        ]);

        $this->assertFalse(data_get($result, 'ok'));
        $this->assertSame('open_meteo', data_get($result, 'provider'));
        $this->assertSame('weather_geocode_failed', data_get($result, 'error_code'));
        $this->assertSame('I couldn’t retrieve the live weather just now. Please try again in a moment.', data_get($result, 'message'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertSentCount(1);
    }

    public function test_external_lookup_routes_structured_weather_forecast_to_open_meteo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 19:20:00', 'America/New_York'));

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-weather-forecast-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather_forecast',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => "what's the weather for tomorrow in Orlando",
                                            'domain' => 'weather',
                                            'intent' => 'weather_forecast',
                                            'location' => 'Orlando, FL',
                                            'date' => '2026-06-25',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);

                $this->assertSame('open_meteo', data_get($lookupResult, 'provider'));
                $this->assertSame('weather_forecast', data_get($lookupResult, 'kind'));
                $this->assertSame('Orlando, Florida, US', data_get($lookupResult, 'location'));
                $this->assertSame('2026-06-25', data_get($lookupResult, 'date'));
                $this->assertSame(91.0, data_get($lookupResult, 'weather.temperature_max_f'));
                $this->assertSame(76.0, data_get($lookupResult, 'weather.temperature_min_f'));

                return Http::response([
                    'id' => 'chatcmpl-weather-forecast-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($lookupResult, 'text'),
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response([
                    'results' => [[
                        'id' => 4167147,
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                $payload = $request->data();
                $this->assertSame('2026-06-25', data_get($payload, 'start_date'));
                $this->assertSame('2026-06-25', data_get($payload, 'end_date'));
                $this->assertSame('weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,wind_speed_10m_max', data_get($payload, 'daily'));

                return Http::response([
                    'timezone' => 'America/New_York',
                    'daily' => [
                        'time' => ['2026-06-25'],
                        'temperature_2m_max' => [91.2],
                        'temperature_2m_min' => [75.9],
                        'precipitation_probability_max' => [40],
                        'precipitation_sum' => [0.08],
                        'weather_code' => [3],
                        'wind_speed_10m_max' => [12.4],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-weather-forecast@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => "what's the weather for tomorrow in orlando",
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Tomorrow in Orlando, Florida, US should be overcast. High 91°F, low 76°F. Precipitation chance up to 40%, wind up to 12 mph.');

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertSentCount(2);
        Carbon::setTestNow();
    }

    public function test_external_lookup_cleans_kpi_weather_prompt_for_direct_open_meteo_lookup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 19:20:00', 'America/New_York'));

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                $this->assertStringContainsString('name=Orlando', urldecode($request->url()));

                return Http::response([
                    'results' => [[
                        'id' => 4167147,
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                $payload = $request->data();
                $this->assertSame('2026-07-08', data_get($payload, 'start_date'));
                $this->assertSame('2026-07-08', data_get($payload, 'end_date'));

                return Http::response([
                    'timezone' => 'America/New_York',
                    'daily' => [
                        'time' => ['2026-07-08'],
                        'temperature_2m_max' => [90.7],
                        'temperature_2m_min' => [75.1],
                        'precipitation_probability_max' => [35],
                        'precipitation_sum' => [0.05],
                        'weather_code' => [2],
                        'wind_speed_10m_max' => [8.4],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-weather-kpi-clean@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-021: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertStringContainsString('Tomorrow in Orlando, Florida, US', (string) $response->json('data.assistant_message.content'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertSentCount(2);
        Carbon::setTestNow();
    }

    public function test_external_lookup_routes_nearby_places_to_google_places(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $chatCalls = 0;
        $geocodeCalls = 0;
        Http::fake(function ($request) use (&$chatCalls, &$geocodeCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-places-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_places',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'Wawa near 32820 closest location and address',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response(['error' => 'Unexpected second chat completion request'], 500);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                $geocodeCalls++;

                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                return Http::response([
                    'places' => [[
                        'id' => 'google-place-1',
                        'displayName' => ['text' => 'Wawa'],
                        'formattedAddress' => '16959 E Colonial Dr, Orlando, FL 32820, USA',
                        'location' => ['latitude' => 28.5687, 'longitude' => -81.1072],
                        'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        'businessStatus' => 'OPERATIONAL',
                        'types' => ['convenience_store', 'gas_station', 'point_of_interest'],
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'where is the nearest wawa to 32820',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertStringContainsString('16959 E Colonial Dr', (string) $response->json('data.assistant_message.content'));
        $this->assertSame(1, $geocodeCalls);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://places.googleapis.com/v1/places:searchText'
                && $request->hasHeader('X-Goog-Api-Key', 'google-test-key')
                && str_contains((string) $request->header('X-Goog-FieldMask')[0], 'places.formattedAddress');
        });
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_google_places_ranks_requested_postal_code_before_farther_non_matching_zip(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-places-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_places',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'nearest Wawa',
                                            'location' => '32820',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response(['error' => 'Unexpected second chat completion request'], 500);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                return Http::response([
                    'places' => [
                        [
                            'id' => 'lee-vista-wawa',
                            'displayName' => ['text' => 'Wawa'],
                            'formattedAddress' => '6500 Lee Vista Blvd, Orlando, FL 32822, USA',
                            'location' => ['latitude' => 28.4696, 'longitude' => -81.3110],
                            'googleMapsUri' => 'https://maps.google.com/?cid=2',
                        ],
                        [
                            'id' => 'colonial-wawa',
                            'displayName' => ['text' => 'Wawa'],
                            'formattedAddress' => '16959 E Colonial Dr, Orlando, FL 32820, USA',
                            'location' => ['latitude' => 28.5687, 'longitude' => -81.1072],
                            'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-ranking@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'where is the nearest wawa to 32820',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertStringContainsString('16959 E Colonial Dr', (string) $response->json('data.assistant_message.content'));
        $this->assertSame(0, $chatCalls);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_osm_places_fallback_handles_nearby_places_without_google_key_or_model_call(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', '');
        config()->set('services.hermes_runtime.tavily_api_key', '');

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.zippopotam.us/us/32820')) {
                return Http::response([
                    'country' => 'United States',
                    'post code' => '32820',
                    'places' => [[
                        'place name' => 'Orlando',
                        'longitude' => '-81.1219',
                        'latitude' => '28.5725',
                        'state' => 'Florida',
                        'state abbreviation' => 'FL',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://photon.komoot.io/api/')) {
                return Http::response([
                    'type' => 'FeatureCollection',
                    'features' => [
                        [
                            'type' => 'Feature',
                            'properties' => [
                                'osm_type' => 'N',
                                'osm_id' => 13819800930,
                                'osm_key' => 'shop',
                                'osm_value' => 'convenience',
                                'housenumber' => '16959',
                                'name' => 'Wawa',
                                'street' => 'East Colonial Drive',
                                'city' => 'Orlando',
                                'state' => 'FL',
                                'country' => 'United States',
                                'postcode' => '32820',
                            ],
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [-81.1289764, 28.5613164],
                            ],
                        ],
                        [
                            'type' => 'Feature',
                            'properties' => [
                                'osm_type' => 'W',
                                'osm_id' => 832051046,
                                'osm_key' => 'shop',
                                'osm_value' => 'convenience',
                                'housenumber' => '6500',
                                'name' => 'Wawa',
                                'street' => 'Lee Vista Boulevard',
                                'city' => 'Orlando',
                                'state' => 'FL',
                                'country' => 'United States',
                                'postcode' => '32820',
                            ],
                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [-81.3110147, 28.4696185],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-osm-fallback@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find the nearest Wawa to 32820 and tell me the address quickly.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $content = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('16959 East Colonial Drive', $content);
        $this->assertStringContainsString('about 0.9 miles away', $content);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://places.googleapis.com/'));
        Http::assertSentCount(2);
    }

    public function test_google_places_strips_generic_store_words_from_brand_place_queries(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $placesTextQuery = null;
        Http::fake(function ($request) use (&$placesTextQuery) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                return Http::response([
                    'id' => 'chatcmpl-places-tool',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_places',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'external_lookup',
                                    'arguments' => json_encode([
                                        'query' => 'Wawa near ZIP code 32820 closest store address',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                $placesTextQuery = data_get($request->data(), 'textQuery');

                return Http::response([
                    'places' => [[
                        'id' => 'colonial-wawa',
                        'displayName' => ['text' => 'Wawa'],
                        'formattedAddress' => '16959 E Colonial Dr, Orlando, FL 32820, USA',
                        'location' => ['latitude' => 28.5687, 'longitude' => -81.1072],
                        'googleMapsUri' => 'https://maps.google.com/?cid=1',
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-generic-store@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find a nearby Wawa around 32820 and tell me the closest address.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('wawa 32820', $placesTextQuery);
        $this->assertStringContainsString('16959 E Colonial Dr', (string) $response->json('data.assistant_message.content'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_google_places_retries_brand_only_query_inside_location_bias(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $placesTextQueries = [];
        Http::fake(function ($request) use (&$placesTextQueries) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                return Http::response(['error' => 'Unexpected model routing call'], 500);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                $textQuery = (string) data_get($request->data(), 'textQuery');
                $placesTextQueries[] = $textQuery;

                if ($textQuery === 'starbucks 32820') {
                    return Http::response(['places' => []], 200);
                }

                return Http::response([
                    'places' => [[
                        'id' => 'avalon-starbucks',
                        'displayName' => ['text' => 'Starbucks Coffee Company'],
                        'formattedAddress' => '321 Avalon Park S Blvd, Orlando, FL 32828, USA',
                        'location' => ['latitude' => 28.5129, 'longitude' => -81.1558],
                        'googleMapsUri' => 'https://maps.google.com/?cid=3',
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-retry-brand@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find the nearest Starbucks to 32820 and tell me the address quickly.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(['starbucks 32820', 'starbucks'], $placesTextQueries);
        $this->assertStringContainsString('321 Avalon Park S Blvd', (string) $response->json('data.assistant_message.content'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_google_places_cleans_verbose_brand_zip_queries_before_searching(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $placesTextQuery = null;
        Http::fake(function ($request) use (&$placesTextQuery) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                return Http::response([
                    'id' => 'chatcmpl-places-tool',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_places',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'external_lookup',
                                    'arguments' => json_encode([
                                        'query' => 'Wawa near ZIP code 32820 (Orlando, FL). Find the closest Wawa and return full street address, city, state, and ZIP.',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                $placesTextQuery = data_get($request->data(), 'textQuery');

                return Http::response([
                    'places' => [[
                        'id' => 'colonial-wawa',
                        'displayName' => ['text' => 'Wawa'],
                        'formattedAddress' => '16959 E Colonial Dr, Orlando, FL 32820, USA',
                        'location' => ['latitude' => 28.5687, 'longitude' => -81.1072],
                        'googleMapsUri' => 'https://maps.google.com/?cid=1',
                    ]],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-verbose-zip@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find the closest Wawa to ZIP code 32820 and give me the full street address.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('wawa 32820', $placesTextQuery);
        $this->assertStringContainsString('16959 E Colonial Dr', (string) $response->json('data.assistant_message.content'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_google_places_prefers_closer_same_brand_over_far_exact_name(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        Http::fake(function ($request) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                return Http::response([
                    'id' => 'chatcmpl-places-tool',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_places',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'external_lookup',
                                    'arguments' => json_encode([
                                        'query' => 'nearest Starbucks to ZIP code 32820 address',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                return Http::response([
                    'places' => [
                        [
                            'id' => 'far-exact-starbucks',
                            'displayName' => ['text' => 'Starbucks'],
                            'formattedAddress' => '626 N Fern Creek Ave, Orlando, FL 32803, USA',
                            'location' => ['latitude' => 28.5515, 'longitude' => -81.3600],
                            'googleMapsUri' => 'https://maps.google.com/?cid=2',
                        ],
                        [
                            'id' => 'near-starbucks-coffee-company',
                            'displayName' => ['text' => 'Starbucks Coffee Company'],
                            'formattedAddress' => '321 Avalon Park S Blvd, Orlando, FL 32828, USA',
                            'location' => ['latitude' => 28.5131, 'longitude' => -81.1530],
                            'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-distance-ranking@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find the nearest Starbucks to 32820 and tell me the address quickly.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $content = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('321 Avalon Park S Blvd', $content);
        $this->assertStringNotContainsString('626 N Fern Creek Ave', str($content)->before('Other close matches')->toString());
    }

    public function test_google_places_ranks_exact_business_name_before_adjacent_service_match(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        Http::fake(function ($request) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                return Http::response([
                    'id' => 'chatcmpl-places-tool',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_places',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'external_lookup',
                                    'arguments' => json_encode([
                                        'query' => 'closest Target to 32820 full street address',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL 32820, USA',
                        'geometry' => [
                            'location' => ['lat' => 28.568, 'lng' => -81.105],
                        ],
                        'address_components' => [[
                            'long_name' => '32820',
                            'short_name' => '32820',
                            'types' => ['postal_code'],
                        ]],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                return Http::response([
                    'places' => [
                        [
                            'id' => 'target-optical',
                            'displayName' => ['text' => 'Target Optical'],
                            'formattedAddress' => '325 N Alafaya Trail, Orlando, FL 32828, USA',
                            'location' => ['latitude' => 28.5525, 'longitude' => -81.2060],
                            'googleMapsUri' => 'https://maps.google.com/?cid=2',
                        ],
                        [
                            'id' => 'target-store',
                            'displayName' => ['text' => 'Target'],
                            'formattedAddress' => '325 N Alafaya Trail, Orlando, FL 32828, USA',
                            'location' => ['latitude' => 28.5525, 'longitude' => -81.2060],
                            'googleMapsUri' => 'https://maps.google.com/?cid=1',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-places-google-exact-business@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Find the closest Target to 32820 and give me the full street address.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $content = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('is Target at 325 N Alafaya Trail', $content);
        $this->assertStringNotContainsString('is Target Optical', $content);
    }

    public function test_external_lookup_routes_general_live_search_to_tavily(): void
    {
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-tavily-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_tavily',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'latest Apple earnings news today',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response(['error' => 'Unexpected second chat completion request'], 500);
            }

            if ($request->url() === 'https://api.tavily.com/search') {
                return Http::response([
                    'query' => 'latest Apple earnings news today',
                    'answer' => 'Apple reported updated earnings results.',
                    'results' => [[
                        'title' => 'Apple earnings',
                        'url' => 'https://example.com/apple-earnings',
                        'content' => 'Apple reported updated earnings results.',
                    ]],
                    'response_time' => '0.42',
                    'usage' => ['credits' => 1],
                    'request_id' => 'tavily-request-1',
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('tool-tavily@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'what is the latest Apple earnings news today',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Apple reported updated earnings results.');

        $this->assertSame(1, $chatCalls);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.tavily.com/search'
                && $request->hasHeader('Authorization', 'Bearer tavily-test-key');
        });
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
    }

    public function test_external_lookup_result_uses_provider_text_without_final_model_call(): void
    {
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-weather-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'current weather in Orlando Florida right now',
                                            'location' => 'Orlando, FL',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response(['error' => 'Unexpected second chat completion request'], 500);
            }

            if ($request->url() === 'https://api.openai.test/v1/responses') {
                return Http::response([
                    'id' => 'resp_weather',
                    'model' => 'gpt-lookup-test',
                    'output_text' => 'It is 82 degrees and partly cloudy in Orlando right now.',
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $token = $this->apiToken('tool-external-fallback@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.assistant_message.content', 'It is 82 degrees and partly cloudy in Orlando right now.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);
        $this->assertSame(1, $chatCalls);
        Http::assertSentCount(2);
    }

    public function test_external_lookup_retries_transport_timeouts(): void
    {
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);
        config()->set('services.hermes_runtime.external_lookup_attempts', 2);

        $chatCalls = 0;
        $lookupCalls = 0;
        Http::fake(function ($request) use (&$chatCalls, &$lookupCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-weather-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'current weather in Orlando Florida right now',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);

                return Http::response([
                    'id' => 'chatcmpl-weather-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($lookupResult, 'text'),
                        ],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://api.openai.test/v1/responses') {
                $lookupCalls++;
                if ($lookupCalls === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received');
                }

                return Http::response([
                    'id' => 'resp_weather',
                    'model' => 'gpt-lookup-test',
                    'output_text' => 'It is 82 degrees and partly cloudy in Orlando right now.',
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $token = $this->apiToken('tool-external-retry@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'It is 82 degrees and partly cloudy in Orlando right now.');

        $this->assertSame(2, $lookupCalls);
    }

    public function test_external_lookup_transport_timeout_returns_specific_tool_result(): void
    {
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);
        config()->set('services.hermes_runtime.external_lookup_attempts', 2);

        $chatCalls = 0;
        $lookupCalls = 0;
        Http::fake(function ($request) use (&$chatCalls, &$lookupCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-weather-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'current weather in Orlando Florida right now',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);
                Assert::assertSame('The live lookup is still taking longer than expected; continue with another available source or ask one focused follow-up.', data_get($lookupResult, 'diagnostic_message'));

                return Http::response([
                    'id' => 'chatcmpl-weather-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($lookupResult, 'message'),
                        ],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://api.openai.test/v1/responses') {
                $lookupCalls++;
                throw new ConnectionException('cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received');
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $token = $this->apiToken('tool-external-timeout@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I’m checking live sources for current weather in Orlando Florida right now. Send me one more detail if you want me to narrow it down further.');

        $this->assertSame(2, $lookupCalls);
    }

    public function test_external_lookup_failure_does_not_fail_agent_runtime(): void
    {
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);

        $chatCalls = 0;
        Http::fake(function ($request) use (&$chatCalls) {
            if ($request->url() === 'https://api.openai.test/v1/chat/completions') {
                $chatCalls++;

                if ($chatCalls === 1) {
                    return Http::response([
                        'id' => 'chatcmpl-weather-tool',
                        'model' => 'gpt-test-tools',
                        'choices' => [[
                            'finish_reason' => 'tool_calls',
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_weather',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'external_lookup',
                                        'arguments' => json_encode([
                                            'query' => 'current weather in Orlando Florida right now',
                                        ], JSON_THROW_ON_ERROR),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);

                return Http::response([
                    'id' => 'chatcmpl-weather-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($lookupResult, 'message'),
                        ],
                    ]],
                ], 200);
            }

            if ($request->url() === 'https://api.openai.test/v1/responses') {
                return Http::response(['error' => ['message' => 'web search unavailable']], 503);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $token = $this->apiToken('tool-external-failure@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I’m checking live sources for current weather in Orlando Florida right now. Send me one more detail if you want me to narrow it down further.');
    }

    public function test_daily_cost_limits_block_tool_runtime_request(): void
    {
        config()->set('services.ai_usage.limits.base_cost_limit', 0.000001);

        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-direct',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Still handled.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-budget@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'hello bean',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.assistant_message.content', 'This account has reached today\'s AI usage limit.');

        Http::assertSentCount(0);
    }

    public function test_tool_runtime_normalizes_object_recurrence_from_agent_tool_calls(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_task',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_task',
                                    'arguments' => json_encode([
                                        'title' => 'Take out the trash',
                                        'due_at' => '2026-06-02T09:00:00-04:00',
                                        'recurrence' => [
                                            'type' => 'interval',
                                            'interval' => 3,
                                            'unit' => 'days',
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_event',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Family standup',
                                        'starts_at' => '2026-06-02T17:00:00-04:00',
                                        'recurrence' => [
                                            'frequency' => 'weekly',
                                        ],
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Added the recurring items.',
                    ],
                ]],
            ], 200);

        $token = $this->premiumApiToken('tool-recurrence@example.com');
        $user = User::where('email', 'tool-recurrence@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add trash every 3 days and family standup weekly',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $task = Task::where('user_id', $user->id)->where('title', 'Take out the trash')->firstOrFail();
        $this->assertSame('interval', $task->metadata['recurrence'] ?? null);
        $this->assertSame(3, $task->metadata['interval'] ?? null);
        $this->assertSame('days', $task->metadata['interval_unit'] ?? null);

        $event = CalendarEvent::where('user_id', $user->id)->where('title', 'Family standup')->firstOrFail();
        $this->assertSame('weekly', $event->recurrence);
        $this->assertSame('weekly', $event->metadata['recurrence'] ?? null);
    }

    public function test_affirmative_follow_up_does_not_recreate_recent_calendar_events(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-initial-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_event',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_calendar_event',
                                'arguments' => json_encode([
                                    'title' => 'Breakfast',
                                    'starts_at' => '2026-06-25T09:00:00-04:00',
                                    'ends_at' => '2026-06-25T10:00:00-04:00',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-follow-up-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_duplicate_event',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Breakfast',
                                        'starts_at' => '2026-06-25T09:00:00-04:00',
                                        'ends_at' => '2026-06-25T10:00:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_reminder',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_reminder',
                                    'arguments' => json_encode([
                                        'title' => 'Breakfast reminder',
                                        'remind_at' => '2026-06-25T08:45:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-follow-up-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I set the reminder.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-follow-up-duplicate@example.com');
        $user = User::where('email', 'tool-follow-up-duplicate@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add breakfast tomorrow at 9am',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $event = CalendarEvent::where('user_id', $user->id)->where('title', 'Breakfast')->firstOrFail();

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'yes',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.duplicate_skipped'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created']);

        $this->assertSame(1, CalendarEvent::where('user_id', $user->id)->where('title', 'Breakfast')->count());
        $this->assertDatabaseHas('reminders', [
            'user_id' => $user->id,
            'title' => 'Breakfast reminder',
            'calendar_event_id' => $event->id,
        ]);
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.calendar_event.duplicate_skipped',
            'status' => 'skipped',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $event->id,
            'title' => 'Breakfast',
        ]);
    }

    public function test_read_only_lookup_cannot_end_mixed_app_change_request(): void
    {
        Carbon::setTestNow('2026-06-24T19:35:00Z');

        $token = $this->premiumApiToken('tool-mixed-action-after-read@example.com');
        $user = User::where('email', 'tool-mixed-action-after-read@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $groceryEvent = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'title' => 'Grocery shopping',
            'starts_at' => Carbon::parse('2026-06-24 15:00:00', 'America/New_York')->utc(),
            'ends_at' => Carbon::parse('2026-06-24 15:45:00', 'America/New_York')->utc(),
            'status' => 'confirmed',
        ]);
        $reminder = Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'calendar_event_id' => $groceryEvent->id,
            'title' => 'Reminder: Grocery shopping',
            'status' => 'pending',
            'remind_at' => Carbon::parse('2026-06-24 14:45:00', 'America/New_York')->utc(),
        ]);

        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-search-reminder',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_search_reminder',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_reminders',
                                'arguments' => json_encode([
                                    'query' => 'grocery shopping',
                                    'date' => '2026-06-24',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-premature-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I found 1 matching reminders.',
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-write-tools',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_create_workout',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_calendar_event',
                                    'arguments' => json_encode([
                                        'title' => 'Workout',
                                        'starts_at' => '2026-06-24T15:00:00-04:00',
                                        'ends_at' => '2026-06-24T16:00:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_move_grocery',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'update_calendar_event',
                                    'arguments' => json_encode([
                                        'id' => $groceryEvent->id,
                                        'starts_at' => '2026-06-24T16:15:00-04:00',
                                        'ends_at' => '2026-06-24T17:00:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            [
                                'id' => 'call_delete_reminder',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'delete_reminder',
                                    'arguments' => json_encode([
                                        'id' => $reminder->id,
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done — I added the workout, moved grocery shopping, and deleted the reminder.',
                    ],
                ]],
            ], 200);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add workout to my calendar and move grocery shopping to be after that, and delete the reminder',
            'metadata' => [
                'source' => 'web',
                'client_context' => [
                    'current_local_time' => '2026-06-24T15:35:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done — I added the workout, moved grocery shopping, and deleted the reminder.')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.updated'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.deleted']);

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'title' => 'Workout',
        ]);
        $groceryEvent->refresh();
        $this->assertSame('2026-06-24T20:15:00+00:00', $groceryEvent->starts_at->utc()->toIso8601String());
        $this->assertDatabaseMissing('reminders', [
            'id' => $reminder->id,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $messages = collect(is_array($payload) ? ($payload['messages'] ?? []) : []);

            return $messages->contains(fn (mixed $message): bool => is_array($message)
                && ($message['role'] ?? null) === 'system'
                && str_contains((string) ($message['content'] ?? ''), 'only read tools have run so far'));
        });
    }

    public function test_base_plan_blocks_recurring_resources_from_bean_tool_calls(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_task',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode([
                                    'title' => 'Pay rent',
                                    'recurrence' => 'monthly',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Recurring tasks need an upgraded plan.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-base-recurrence@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add pay rent every month',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.action.failed'])
            ->assertJsonFragment(['reason' => 'Recurring tasks are available on Premium, Pro, and Enterprise plans.']);

        $this->assertDatabaseMissing('tasks', [
            'title' => 'Pay rent',
        ]);
    }

    public function test_successful_tool_execution_returns_fallback_if_final_narration_call_fails(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode([
                                    'title' => 'Buy milk',
                                    'type' => 'todo',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push(['error' => ['message' => 'tool_choice is invalid without tools']], 400);

        $token = $this->apiToken('tool-final-fallback@example.com');
        $user = User::where('email', 'tool-final-fallback@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add buy milk to my tasks',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I added Buy milk to your tasks.')
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Buy milk',
        ]);
    }

    public function test_app_crud_turn_uses_slim_tool_context_and_records_timings(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-slim-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_task',
                            'type' => 'function',
                            'function' => [
                                'name' => 'create_task',
                                'arguments' => json_encode([
                                    'title' => 'Buy milk',
                                    'type' => 'todo',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-slim-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Done - I added Buy milk.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-slim-crud@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'add buy milk to my tasks',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $events = collect($response->json('data.events'));
        $started = $events->firstWhere('event_type', 'runtime.tool_model_started');
        $completed = $events->firstWhere('event_type', 'runtime.tool_model_completed');

        $this->assertSame('app_crud', data_get($started, 'payload.tool_mode'));
        $this->assertSame('app_crud', data_get($completed, 'payload.tool_mode'));
        $this->assertIsInt(data_get($completed, 'payload.duration_ms'));
        $this->assertIsInt(data_get($completed, 'payload.model_call_ms'));
        $this->assertIsInt(data_get($completed, 'payload.tool_execution_ms'));
        $this->assertSame(1, data_get($completed, 'payload.model_call_count'));
        $this->assertSame(1, data_get($completed, 'payload.tool_execution_count'));

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $toolNames = collect($payload['tools'] ?? [])
                ->map(fn (array $tool): ?string => data_get($tool, 'function.name'));
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $toolNames->contains('create_task')
                && $toolNames->contains('create_calendar_event')
                && ! $toolNames->contains('external_lookup')
                && ! $toolNames->contains('remember_memory')
                && ! $toolNames->contains('update_agent_profile')
                && data_get($context, 'memory_context.items') === []
                && data_get($context, 'memory_context.summaries') === [];
        });
    }

    public function test_app_crud_planner_creates_each_dated_calendar_event_in_one_request(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->apiToken('tool-multi-dated-events@example.com');
        $user = User::where('email', 'tool-multi-dated-events@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Ok, please add the following items to my calendar: 7/9 Dr Chen Cardio at 100 N Dean Rd. at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-30T22:19:29-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $events = collect($response->json('data.events'));
        $userMessageId = (int) $response->json('data.user_message.id');
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(3, $events->where('event_type', 'assistant.calendar_event.created'));
        $events
            ->whereIn('event_type', ['assistant.work_item.planned', 'assistant.calendar_event.created'])
            ->each(function (array $event) use ($userMessageId): void {
                $this->assertSame($userMessageId, (int) data_get($event, 'payload.user_message_id'));
                $this->assertSame($userMessageId, (int) data_get($event, 'payload.message_id'));
            });
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Dr Chen Cardio', 'location' => '100 N Dean Rd']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Ventura']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Azalea Lane']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_natural_dated_calendar_list_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->apiToken('tool-natural-dated-events@example.com');
        $user = User::where('email', 'tool-natural-dated-events@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T10:10:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $events = collect($response->json('data.events'));
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(3, $events->where('event_type', 'assistant.calendar_event.created'));
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Dr Chen Cardio', 'location' => '100 N Dean rd']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Ventura']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Azalea Lane']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_does_not_treat_oil_change_as_update_request(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->apiToken('tool-oil-change-events@example.com');
        $user = User::where('email', 'tool-oil-change-events@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'REQ-013: Add three calendar events: 7/11 Oil change at 500 Service Rd at 8am, 7/17 Dentist consult at 3pm, and 7/22 School pickup at 2pm.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T09:55:42-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $events = collect($response->json('data.events'));
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(3, $events->where('event_type', 'assistant.calendar_event.created'));
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Oil change', 'location' => '500 Service Rd']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Dentist consult']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'School pickup']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_afternoon_plan_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-afternoon-plan@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan the rest of my afternoon: add a 45 minute workout, grocery store after that, cook dinner after that, create a simple dinner recipe note, and make a grocery checklist note.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $events = collect($response->json('data.events'));
        $this->assertCount(5, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(3, $events->where('event_type', 'assistant.calendar_event.created'));
        $this->assertCount(2, $events->where('event_type', 'assistant.note.created'));
        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'Workout']);
        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'Grocery shopping']);
        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'Cook dinner']);
        $this->assertDatabaseHas('notes', ['title' => 'Simple dinner recipe']);
        $this->assertDatabaseHas('notes', ['title' => 'Grocery checklist']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_task_reminder_note_workflow_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-task-reminder-note@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a task to review insurance paperwork tomorrow morning, remind me 30 minutes before, and save a note with the documents I should bring.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $events = collect($response->json('data.events'));
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(1, $events->where('event_type', 'assistant.task.created'));
        $this->assertCount(1, $events->where('event_type', 'assistant.reminder.created'));
        $this->assertCount(1, $events->where('event_type', 'assistant.note.created'));
        $this->assertDatabaseHas('tasks', ['conversation_session_id' => $sessionId, 'title' => 'review insurance paperwork']);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'review insurance paperwork']);
        $this->assertDatabaseHas('notes', ['title' => 'Review Insurance Paperwork note']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_does_not_treat_meeting_agenda_task_as_calendar_meeting(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-meeting-agenda-task@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a task to prep the meeting agenda tomorrow morning, remind me 45 minutes before, and save a note called Meeting Prep Notes.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $events = collect($response->json('data.events'));
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(1, $events->where('event_type', 'assistant.task.created'));
        $this->assertCount(1, $events->where('event_type', 'assistant.reminder.created'));
        $this->assertCount(1, $events->where('event_type', 'assistant.note.created'));
        $this->assertDatabaseHas('tasks', ['conversation_session_id' => $sessionId, 'title' => 'prep the meeting agenda']);
        $this->assertDatabaseHas('notes', ['title' => 'Meeting Prep Notes']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_note_with_reminder_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-note-reminder@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a note called Quick Dinner Ideas with three fast meals, pin it, and add a reminder tomorrow at 4pm to pick one.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('notes', ['title' => 'Quick Dinner Ideas', 'is_pinned' => true]);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'Pick one from Quick Dinner Ideas']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_simple_kpi_actions_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'America/New_York'));
        Http::fake();

        $token = $this->premiumApiToken('tool-simple-kpi-actions@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $clientContext = [
            'current_local_time' => '2026-07-07T10:00:00-04:00',
            'timezone' => 'America/New_York',
            'timezone_offset' => '-04:00',
            'timezone_offset_minutes' => -240,
        ];

        $eventResponse = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-011: Create a calendar event called KPI Focus Block tomorrow at 9am for 30 minutes.',
            'metadata' => ['client_context' => $clientContext],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $reminderResponse = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-013: Set a reminder tomorrow at 8am to check the KPI dashboard.',
            'metadata' => ['client_context' => $clientContext],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $noteResponse = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-014: Create a note called KPI Test Note with three short bullets.',
            'metadata' => ['client_context' => $clientContext],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertCount(1, collect($eventResponse->json('data.events'))->where('event_type', 'assistant.calendar_event.created'));
        $this->assertCount(1, collect($reminderResponse->json('data.events'))->where('event_type', 'assistant.reminder.created'));
        $this->assertCount(1, collect($noteResponse->json('data.events'))->where('event_type', 'assistant.note.created'));
        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'KPI Focus Block']);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'check the KPI dashboard']);
        $this->assertDatabaseHas('notes', ['title' => 'KPI Test Note']);
        Http::assertSentCount(0);
        Carbon::setTestNow();
    }

    public function test_app_crud_planner_does_not_treat_note_reminder_update_word_as_update_request(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-note-update-word@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'REQ-038: Create a note called Gift Ideas List with a few practical ideas, pin it, and add a reminder tomorrow at 6pm to update it.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T09:55:42-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('notes', ['title' => 'Gift Ideas List', 'is_pinned' => true]);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'Update Gift Ideas List']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_project_followup_workflow_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-project-followup@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a project follow-up workflow: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'Project follow-up focus block']);
        $this->assertDatabaseHas('tasks', ['conversation_session_id' => $sessionId, 'title' => 'Prepare notes for project follow-up']);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'Prepare notes for project follow-up']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_handles_kpi_friday_plan_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-kpi-friday-plan@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'KPI-020: Plan Friday morning with a calendar focus block at 9am, task to gather notes, and reminder Thursday afternoon.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-07T20:00:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('calendar_events', ['conversation_session_id' => $sessionId, 'title' => 'Focus block']);
        $this->assertDatabaseHas('tasks', ['conversation_session_id' => $sessionId, 'title' => 'gather notes']);
        $this->assertDatabaseHas('reminders', ['conversation_session_id' => $sessionId, 'title' => 'Focus block']);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_moves_event_and_creates_relative_reminder_without_model_call(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->premiumApiToken('tool-move-event-reminder@example.com');
        $user = User::where('email', 'tool-move-event-reminder@example.com')->firstOrFail();
        $workspaceId = (int) $user->default_workspace_id;
        $event = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Workout',
            'starts_at' => Carbon::parse('2026-07-02T15:00:00-04:00')->utc(),
            'ends_at' => Carbon::parse('2026-07-02T15:45:00-04:00')->utc(),
        ]);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Move my next workout event to 5:30pm if there is one today, then create a reminder 15 minutes before it.',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-07-02T08:55:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $events = collect($response->json('data.events'));
        $this->assertCount(2, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(1, $events->where('event_type', 'assistant.calendar_event.updated'));
        $this->assertCount(1, $events->where('event_type', 'assistant.reminder.created'));
        $event->refresh();
        $this->assertSame('2026-07-02T21:30:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-07-02T22:15:00+00:00', $event->ends_at->utc()->toIso8601String());
        $this->assertDatabaseHas('reminders', [
            'conversation_session_id' => $sessionId,
            'calendar_event_id' => $event->id,
            'title' => 'Reminder: Workout',
        ]);
        Http::assertSentCount(0);
    }

    public function test_app_crud_planner_accepts_dash_separated_calendar_dates(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fake();

        $token = $this->apiToken('tool-dash-dated-events@example.com');
        $user = User::where('email', 'tool-dash-dated-events@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Ok, please add the following items to my calendar: 7-9 Dr Chen Cardio at 100 N Dean Rd. at 3pm, 7-15 Ventura at 6pm, and 7-19 Azalea Lane at 2pm',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-30T22:19:29-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $events = collect($response->json('data.events'));
        $this->assertCount(3, $events->where('event_type', 'assistant.work_item.planned'));
        $this->assertCount(3, $events->where('event_type', 'assistant.calendar_event.created'));
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Dr Chen Cardio', 'location' => '100 N Dean Rd']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Ventura']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Azalea Lane']);
        Http::assertSentCount(0);
    }

    public function test_day_context_tool_returns_only_items_on_the_requested_local_date(): void
    {
        $token = $this->apiToken('tool-day-context-date@example.com');
        $user = User::where('email', 'tool-day-context-date@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $workspaceId = (int) $session->workspace_id;

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => "What's on my calendar for today?",
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);

        foreach ([
            ['Today task', '2026-07-10 14:00:00'],
            ['Tomorrow task', '2026-07-11 14:00:00'],
        ] as [$title, $dueAt]) {
            Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'title' => $title,
                'status' => 'open',
                'due_at' => Carbon::parse($dueAt, 'America/New_York')->utc(),
            ]);
        }
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Undated task',
            'status' => 'open',
            'due_at' => null,
        ]);

        $service = app(HermesToolRuntimeService::class);
        $method = (new \ReflectionClass($service))->getMethod('dayContextForTool');
        $method->setAccessible(true);
        $result = $method->invoke($service, $session, ['date' => '2026-07-10']);

        $this->assertSame(['Today task'], collect($result['tasks'])->pluck('title')->all());
    }

    public function test_day_context_fallback_dedupes_identical_items(): void
    {
        $service = app(HermesToolRuntimeService::class);
        $method = (new \ReflectionClass($service))->getMethod('dayContextFallbackContent');
        $method->setAccessible(true);

        $content = (string) $method->invoke($service, [
            'date' => '2026-07-02',
            'calendar_events' => [
                ['display_start_date' => '2026-07-02', 'display_start_time' => '3:50 PM', 'title' => 'Grocery shopping'],
                ['display_start_date' => '2026-07-02', 'display_start_time' => '3:50 PM', 'title' => 'Grocery shopping'],
                ['display_start_date' => '2026-07-03', 'display_start_time' => '9:00 AM', 'title' => 'Tomorrow event'],
            ],
            'tasks' => [
                ['display_due_date' => '2026-07-02', 'display_due_time' => '4:00 PM', 'title' => 'Today task'],
                ['display_due_date' => '2026-07-03', 'display_due_time' => '8:00 AM', 'title' => 'Tomorrow task'],
                ['display_due_date' => null, 'display_due_time' => '', 'title' => 'Undated task'],
            ],
            'reminders' => [
                ['display_remind_date' => '2026-07-02', 'display_remind_time' => '5:15 PM', 'title' => 'Reminder: Workout'],
                ['display_remind_date' => '2026-07-02', 'display_remind_time' => '5:15 PM', 'title' => 'Reminder: Workout'],
                ['display_remind_date' => '2026-07-03', 'display_remind_time' => '7:00 AM', 'title' => 'Tomorrow reminder'],
            ],
        ]);

        $this->assertSame(1, substr_count($content, 'Event: Grocery shopping'));
        $this->assertSame(1, substr_count($content, 'Reminder: Workout'));
        $this->assertStringNotContainsString('Reminder: Reminder:', $content);
        $this->assertStringNotContainsString('Tomorrow event', $content);
        $this->assertStringNotContainsString('Tomorrow task', $content);
        $this->assertStringNotContainsString('Tomorrow reminder', $content);
        $this->assertStringNotContainsString('Undated task', $content);
    }

    public function test_native_read_fallback_prefers_day_context_over_request_history_when_both_were_read(): void
    {
        $service = app(HermesToolRuntimeService::class);
        $method = (new \ReflectionClass($service))->getMethod('nativeReadFallbackContent');
        $method->setAccessible(true);

        $content = (string) $method->invoke($service, [
            [
                'ok' => true,
                'tool' => 'get_day_context',
                'date' => '2026-07-02',
                'calendar_events' => [
                    ['display_start_time' => '5:30 PM', 'title' => 'Workout'],
                ],
                'tasks' => [],
                'reminders' => [],
            ],
            [
                'ok' => true,
                'tool' => 'get_request_history',
                'items' => [
                    [
                        'created_at' => '2026-07-02T09:47:48-04:00',
                        'content' => 'REQ-100: What remains today, and suggest one practical improvement to the plan.',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Here is what is coming up for 2026-07-02', $content);
        $this->assertStringContainsString('Event: Workout', $content);
        $this->assertStringNotContainsString('request history', $content);
        $this->assertStringNotContainsString('REQ-100', $content);
    }

    public function test_app_crud_planner_reports_partial_failure_without_falling_back_or_duplicating_completed_writes(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fakeSequence()
            ->push($this->plannerResponse([
                [
                    'type' => 'calendar_event.create',
                    'client_action_key' => 'event_1',
                    'parameters' => [
                        'title' => 'Normal appointment',
                        'starts_at' => '2026-07-09T15:00:00-04:00',
                    ],
                ],
                [
                    'type' => 'calendar_event.create',
                    'client_action_key' => 'event_2',
                    'parameters' => [
                        'title' => 'Recurring appointment',
                        'starts_at' => '2026-07-10T15:00:00-04:00',
                        'recurrence' => 'weekly',
                    ],
                ],
            ]), 200);

        $token = $this->apiToken('tool-partial-planner@example.com');
        $user = User::where('email', 'tool-partial-planner@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add Normal appointment to my calendar and add Recurring appointment to my calendar too',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-30T22:19:29-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created'])
            ->assertJsonFragment(['event_type' => 'assistant.action.failed']);

        $this->assertStringContainsString('I completed 1 of 2 requested changes.', $response->json('data.assistant_message.content'));
        $this->assertStringContainsString('Recurring calendar events are available on Premium, Pro, and Enterprise plans. Upgrade your plan to use this feature.', $response->json('data.assistant_message.content'));
        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $user->id, 'title' => 'Normal appointment']);
        $this->assertDatabaseMissing('calendar_events', ['user_id' => $user->id, 'title' => 'Recurring appointment']);
        Http::assertSentCount(1);
    }

    public function test_app_crud_planner_returns_upgrade_message_when_base_plan_note_limit_is_reached(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);

        Http::fakeSequence()
            ->push($this->plannerResponse([
                [
                    'type' => 'note.create',
                    'parameters' => [
                        'title' => 'hello',
                        'plain_text' => 'hello',
                        'body' => 'hello',
                    ],
                ],
            ]), 200);

        $token = $this->apiToken('tool-base-note-plan@example.com');
        $user = User::where('email', 'tool-base-note-plan@example.com')->firstOrFail();
        $workspace = $user->workspaces()->firstOrFail();
        for ($i = 1; $i <= 10; $i++) {
            Note::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => "Existing note {$i}",
                'plain_text' => 'Already at the base note limit.',
            ]);
        }
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'create a new note called hello',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Your current plan includes up to 10 notes. Upgrade your plan to create and manage more notes.')
            ->assertJsonFragment(['event_type' => 'assistant.action.failed'])
            ->assertJsonFragment(['reason' => 'Your current plan includes up to 10 notes.']);

        $this->assertStringNotContainsString('Please try those again', $response->json('data.assistant_message.content'));
        $this->assertDatabaseMissing('notes', [
            'title' => 'hello',
        ]);
    }

    private function plannerResponse(array $actions): array
    {
        return [
            'id' => 'chatcmpl-crud-planner',
            'model' => 'gpt-test-tools',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode(['actions' => $actions], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 10,
                'total_tokens' => 20,
            ],
        ];
    }
}
