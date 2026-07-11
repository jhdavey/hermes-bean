<?php

namespace App\Services;

use App\Models\AgentProfile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiVoiceService
{
    public const DEFAULT_VOICE = 'alloy';

    public const DEFAULT_REALTIME_MODEL = 'gpt-realtime';

    private const VOICES = [
        'alloy' => 'Alloy',
        'ash' => 'Ash',
        'ballad' => 'Ballad',
        'coral' => 'Coral',
        'echo' => 'Echo',
        'fable' => 'Fable',
        'nova' => 'Nova',
        'onyx' => 'Onyx',
        'sage' => 'Sage',
        'shimmer' => 'Shimmer',
    ];

    public function availableVoices(): array
    {
        return collect(self::VOICES)
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    public function voiceKeys(): array
    {
        return array_keys(self::VOICES);
    }

    public function defaultVoiceSettings(?string $voice = null): array
    {
        $selected = $this->normalizeVoice($voice);

        return [
            'provider' => 'openai_realtime',
            'voice' => $selected,
            'voice_label' => self::VOICES[$selected],
            'realtime_model' => $this->realtimeModel(),
            'available_voices' => $this->availableVoices(),
        ];
    }

    public function publicSettingsFor(AgentProfile $profile): array
    {
        return $this->defaultVoiceSettings(data_get($profile->settings ?? [], 'voice.voice'));
    }

    public function updateProfileVoice(AgentProfile $profile, string $voice): AgentProfile
    {
        $settings = $profile->settings ?? [];
        $settings['voice'] = $this->defaultVoiceSettings($voice);
        $profile->forceFill(['settings' => $settings])->save();

        return $profile->refresh();
    }

    public function createRealtimeSession(AgentProfile $profile, array $context = []): array
    {
        $voice = $this->normalizeVoice(data_get($profile->settings ?? [], 'voice.voice'));
        $model = $this->realtimeModel();
        $instructions = $this->realtimeInstructions($profile, $context);

        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout((float) config('services.openai.realtime_session_timeout', 10))
            ->post($this->endpoint('/realtime/client_secrets'), [
                'session' => [
                    'type' => 'realtime',
                    'model' => $model,
                    'instructions' => $instructions,
                    'audio' => [
                        'input' => [
                            'transcription' => [
                                'model' => (string) config('services.openai.realtime_transcription_model', 'gpt-4o-mini-transcribe'),
                                'language' => 'en',
                                'prompt' => 'The user speaks US English. The wake phrase is "Hey Bean" and the assistant is named Bean. Transcribe only the English words that are spoken.',
                            ],
                            'turn_detection' => [
                                'type' => 'server_vad',
                                'threshold' => (float) config('services.openai.realtime_vad_threshold', 0.5),
                                'prefix_padding_ms' => (int) config('services.openai.realtime_vad_prefix_padding_ms', 300),
                                'silence_duration_ms' => (int) config('services.openai.realtime_vad_silence_duration_ms', 650),
                                'create_response' => false,
                                'interrupt_response' => true,
                            ],
                        ],
                        'output' => [
                            'voice' => $voice,
                        ],
                    ],
                    'tools' => $this->realtimeTools(),
                    'tool_choice' => 'auto',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI realtime session request failed with status '.$response->status().'.');
        }

        $payload = $response->json();
        $clientSecret = data_get($payload, 'value') ?: data_get($payload, 'client_secret.value') ?: data_get($payload, 'client_secret');
        if (! is_string($clientSecret) || trim($clientSecret) === '') {
            throw new RuntimeException('OpenAI realtime session response did not include a client secret.');
        }

        return [
            'provider' => 'openai_realtime',
            'model' => $model,
            'voice' => $voice,
            'voice_label' => self::VOICES[$voice],
            'client_secret' => $clientSecret,
            'expires_at' => data_get($payload, 'expires_at') ?: data_get($payload, 'client_secret.expires_at'),
            'session_id' => data_get($payload, 'session.id') ?: data_get($payload, 'id'),
            'realtime_url' => rtrim((string) config('services.openai.realtime_webrtc_url', 'https://api.openai.com/v1/realtime/calls'), '?'),
            'tools' => $this->realtimeTools(),
        ];
    }

    public function realtimeTools(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'send_bean_request',
                'description' => 'Send a user request to HeyBean Laravel when app data, tools, tasks, reminders, notes, calendar, memory, approvals, or longer-running agent work are needed. Laravel authenticates, scopes, executes, persists, and returns the result.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'request' => [
                            'type' => 'string',
                            'description' => 'The user request to execute in HeyBean.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why Laravel app/tool execution is needed.',
                        ],
                    ],
                    'required' => ['request'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function normalizeVoice(?string $voice): string
    {
        $key = strtolower(trim((string) $voice));

        return array_key_exists($key, self::VOICES) ? $key : self::DEFAULT_VOICE;
    }

    private function realtimeInstructions(AgentProfile $profile, array $context): string
    {
        $beanName = trim((string) ($profile->display_name ?: 'Bean')) ?: 'Bean';
        $timezone = trim((string) data_get($context, 'timezone', config('app.timezone', 'UTC'))) ?: 'UTC';

        return trim("You are {$beanName}, HeyBean's realtime voice assistant. Always understand and respond in US English. Never switch languages or mirror a transcript in another language. If speech appears garbled or non-English, ask the user in English to repeat it. Be warm, concise, and fast like a world-class voice assistant. The user starts a conversation by saying \"Hey Bean\" or by tapping the Bean button. The client controls whether the conversation is active or wake-only. While active, accept natural follow-ups without repeating the wake word. After the user says thanks, thank you, nevermind, cancel, stop, stop talking, stop listening, that's all, all done, no thanks, goodbye, bye, or a close variant, end that conversation and remain wake-only: do not answer, call tools, or resume from any later speech until the user explicitly says \"Hey Bean\" again. Never treat your own audio, a partial transcript, or a recognition artifact as a new request. If the user interrupts while you are speaking, stop and listen. For simple conversational answers, answer directly in one or two short, naturally speakable sentences. For requests that need HeyBean app data, mutations, current external information, approvals, or longer-running work, call send_bean_request instead of inventing results. Never claim to create, update, delete, complete, schedule, fetch private app data, or look up live facts unless Laravel returns that result. User timezone: {$timezone}.");
    }

    private function realtimeModel(): string
    {
        return (string) config('services.openai.realtime_model', self::DEFAULT_REALTIME_MODEL);
    }

    private function apiKey(): string
    {
        $key = (string) (config('services.openai.server_api_key') ?: config('services.hermes_runtime.api_key'));
        if ($key === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        return $key;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.hermes_runtime.api_base', 'https://api.openai.com/v1'), '/').$path;
    }
}
