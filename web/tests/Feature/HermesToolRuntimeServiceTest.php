<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HermesToolRuntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
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
            'content' => 'hello bean',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I can help with that.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_started'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $systemPrompt = (string) data_get($payload, 'messages.0.content');
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);
            $toolNames = collect($payload['tools'] ?? [])->map(fn (array $tool): ?string => data_get($tool, 'function.name'));

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($payload, 'messages.0.role') === 'system'
                && str_contains($systemPrompt, 'Ask this exact location question after learning the user\'s name')
                && str_contains($systemPrompt, 'What city are you in? This will help me be more useful')
                && str_contains($systemPrompt, 'just say "skip"')
                && str_contains($systemPrompt, 'A skipped location still counts as enough onboarding detail')
                && str_contains($systemPrompt, "Balanced helper, Motivating coach, Detail organizer, Creative partner, Direct operator, and Gentle companion")
                && str_contains($systemPrompt, "Settings > Bean preferences")
                && data_get($payload, 'messages.1.role') === 'system'
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'hello bean'
                && ! array_key_exists('dashboard_state', $context)
                && isset($context['temporal_context'], $context['workspace'], $context['agent_profile'])
                && $toolNames->contains('search_tasks')
                && $toolNames->contains('external_lookup')
                && $toolNames->contains('update_task')
                && ! $toolNames->contains('create_approval');
        });
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
            ->assertJsonPath('data.assistant_message.content', 'All set, Harley.');

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
            ->assertJsonPath('data.assistant_message.content', 'All set — I will keep your location private.');

        $user = User::where('email', 'onboarding-skip-location@example.com')->firstOrFail();
        $profile = $user->agentProfile()->firstOrFail();

        $this->assertTrue($user->refresh()->onboard_complete);
        $this->assertSame('gentle', $profile->settings['personality_type']);
        $this->assertSame('Harley', data_get($profile->settings, 'onboarding.name'));
        $this->assertNull(data_get($profile->settings, 'onboarding.city'));
        $this->assertNull(data_get($profile->settings, 'onboarding.location'));
        $this->assertTrue(data_get($profile->settings, 'onboarding.completed'));
    }

    public function test_tool_runtime_receives_voice_quick_reply_context_for_continuation(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-voice-context',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I would add a protein and something fresh, depending on what you have.',
                    ],
                ]],
            ], 200);

        $token = $this->apiToken('tool-voice-context@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'what should we have for dinner tonight?',
            'metadata' => [
                'client_context' => ['timezone' => 'America/New_York'],
                'voice_context' => [
                    'mode' => 'live_voice',
                    'quick_reply' => 'Got it, a quick easy dinner could be tacos or pasta.',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && str_contains((string) data_get($payload, 'messages.0.content'), 'already said that sentence aloud')
                && data_get($context, 'voice_context.mode') === 'live_voice'
                && data_get($context, 'voice_context.quick_reply') === 'Got it, a quick easy dinner could be tacos or pasta.'
                && data_get($context, 'temporal_context.client_context.timezone') === 'America/New_York';
        });
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
            ->assertJsonPath('data.assistant_message.content', 'Marked Litter Box complete.')
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

        Http::assertSentCount(2);
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

        $token = $this->apiToken('tool-note-envelope@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'create a note with directions to boil an egg',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I created the note Directions to boil an egg.');

        $this->assertDatabaseHas('notes', [
            'title' => 'Directions to boil an egg',
        ]);
        $this->assertSame(1, Note::where('title', 'Directions to boil an egg')->count());
    }

    public function test_tool_runtime_executes_external_lookup_tool_call(): void
    {
        config()->set('services.hermes_runtime.external_lookup_model', 'gpt-lookup-test');

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

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $lookupResult = json_decode((string) data_get($toolOutput, 'content'), true, flags: JSON_THROW_ON_ERROR);

                return Http::response([
                    'id' => 'chatcmpl-external-final',
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

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $toolMessage = collect($payload['messages'] ?? [])->firstWhere('role', 'tool');
            if (! $toolMessage) {
                return false;
            }
            $toolOutput = json_decode((string) data_get($toolMessage, 'content'), true, flags: JSON_THROW_ON_ERROR);

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && data_get($toolOutput, 'tool') === 'external_lookup'
                && data_get($toolOutput, 'text') === 'The local Ace Hardware closes at 8 PM today.'
                && data_get($toolOutput, 'citations.0.url') === 'https://www.acehardware.com/store-details/example';
        });

        Http::assertSentCount(3);
    }

    public function test_external_lookup_routes_current_weather_to_open_meteo(): void
    {
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
        Http::assertSentCount(3);
    }

    public function test_external_lookup_routes_nearby_places_to_google_places(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $chatCalls = 0;
        $geocodeCalls = 0;
        $capturedLookupResult = [];
        Http::fake(function ($request) use (&$chatCalls, &$geocodeCalls, &$capturedLookupResult) {
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

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $capturedLookupResult = json_decode((string) data_get($toolOutput, 'content'), true) ?: [];

                return Http::response([
                    'id' => 'chatcmpl-places-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($capturedLookupResult, 'text'),
                        ],
                    ]],
                ], 200);
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

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'where is the nearest wawa to 32820',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('google_places', data_get($capturedLookupResult, 'provider'));
        $this->assertStringContainsString('16959 E Colonial Dr', (string) data_get($capturedLookupResult, 'text'));
        $this->assertTrue((bool) data_get($capturedLookupResult, 'places.0.postal_code_match'));
        $this->assertSame(1, $geocodeCalls);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://places.googleapis.com/v1/places:searchText'
                && $request->hasHeader('X-Goog-Api-Key', 'google-test-key')
                && str_contains((string) $request->header('X-Goog-FieldMask')[0], 'places.formattedAddress');
        });
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_google_places_ranks_requested_postal_code_before_farther_non_matching_zip(): void
    {
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');

        $chatCalls = 0;
        $capturedLookupResult = [];
        Http::fake(function ($request) use (&$chatCalls, &$capturedLookupResult) {
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

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $capturedLookupResult = json_decode((string) data_get($toolOutput, 'content'), true) ?: [];

                return Http::response([
                    'id' => 'chatcmpl-places-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($capturedLookupResult, 'text'),
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

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'where is the nearest wawa to 32820',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('google_places', data_get($capturedLookupResult, 'provider'));
        $this->assertSame('16959 E Colonial Dr, Orlando, FL 32820, USA', data_get($capturedLookupResult, 'places.0.address'));
        $this->assertSame('6500 Lee Vista Blvd, Orlando, FL 32822, USA', data_get($capturedLookupResult, 'places.1.address'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
    }

    public function test_external_lookup_routes_general_live_search_to_tavily(): void
    {
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');

        $chatCalls = 0;
        $capturedLookupResult = [];
        Http::fake(function ($request) use (&$chatCalls, &$capturedLookupResult) {
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

                $messages = $request->data()['messages'] ?? [];
                $toolOutput = collect($messages)->firstWhere('role', 'tool');
                $capturedLookupResult = json_decode((string) data_get($toolOutput, 'content'), true) ?: [];

                return Http::response([
                    'id' => 'chatcmpl-tavily-final',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => data_get($capturedLookupResult, 'text'),
                        ],
                    ]],
                ], 200);
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

        $this->assertSame('tavily_search', data_get($capturedLookupResult, 'provider'));
        $this->assertSame('Apple reported updated earnings results.', data_get($capturedLookupResult, 'text'));
        $this->assertSame('https://example.com/apple-earnings', data_get($capturedLookupResult, 'citations.0.url'));
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.tavily.com/search'
                && $request->hasHeader('Authorization', 'Bearer tavily-test-key');
        });
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
    }

    public function test_external_lookup_result_falls_back_if_final_model_call_fails(): void
    {
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);

        $chatCalls = 0;
        $capturedToolContent = null;
        Http::fake(function ($request) use (&$chatCalls, &$capturedToolContent) {
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

                $messages = $request->data()['messages'] ?? [];
                $capturedToolContent = (string) data_get(collect($messages)->firstWhere('role', 'tool'), 'content', '');

                return Http::response(['error' => ['message' => 'temporary final model failure']], 500);
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
        $this->assertStringContainsString('It is 82 degrees and partly cloudy in Orlando right now.', (string) $capturedToolContent);
        $response->assertJsonPath('data.assistant_message.content', 'It is 82 degrees and partly cloudy in Orlando right now.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);
        Http::assertSentCount(3);
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
            ->assertJsonPath('data.assistant_message.content', 'The live lookup timed out before it could return current information.');

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
            ->assertJsonPath('data.assistant_message.content', 'The external lookup failed.');
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
        ])->assertStatus(429)
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
                'id' => 'chatcmpl-initial-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I added breakfast. Do you want a reminder for it?',
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
                'source' => 'flutter',
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
            ->assertJsonPath('data.assistant_message.content', 'I added Buy milk to your tasks.')
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Buy milk',
        ]);
    }
}
