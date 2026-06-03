<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            $context = json_decode(str_replace("Runtime context:\n", '', (string) data_get($payload, 'messages.1.content')), true, flags: JSON_THROW_ON_ERROR);
            $toolNames = collect($payload['tools'] ?? [])->map(fn (array $tool): ?string => data_get($tool, 'function.name'));

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($payload, 'messages.0.role') === 'system'
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
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Litter Box',
            'type' => 'todo',
            'status' => 'open',
        ]);

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

        Http::assertSentCount(2);
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

    public function test_external_lookup_result_falls_back_if_final_model_call_fails(): void
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

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'It is 82 degrees and partly cloudy in Orlando right now.')
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

        $token = $this->apiToken('tool-recurrence@example.com');
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
