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

    public function createRealtimeCall(
        AgentProfile $profile,
        string $sdp,
        array $context = [],
        ?string $safetyIdentifier = null,
    ): array {
        $voice = $this->normalizeVoice(data_get($profile->settings ?? [], 'voice.voice'));
        $model = $this->realtimeModel();
        $session = $this->realtimeSessionConfiguration($profile, $context, $voice, $model);

        $response = Http::withToken($this->apiKey())
            ->accept('application/sdp')
            ->when($safetyIdentifier, fn ($request) => $request->withHeaders([
                'OpenAI-Safety-Identifier' => $safetyIdentifier,
            ]))
            ->timeout((float) config('services.openai.realtime_session_timeout', 10))
            ->asMultipart()
            ->attach('sdp', $sdp)
            ->attach('session', json_encode($session, JSON_THROW_ON_ERROR))
            ->post($this->endpoint('/realtime/calls'));

        if (! $response->successful()) {
            $providerCode = preg_replace(
                '/[^a-z0-9_.-]+/i',
                '_',
                (string) $response->json('error.code', 'unknown_error'),
            );
            $providerMessage = preg_replace(
                '/\s+/',
                ' ',
                (string) $response->json('error.message', 'The provider rejected the realtime call.'),
            );

            throw new RuntimeException(sprintf(
                'OpenAI realtime call request failed with status %d (%s): %s',
                $response->status(),
                substr((string) $providerCode, 0, 80),
                substr((string) $providerMessage, 0, 500),
            ));
        }

        $answerSdp = trim($response->body());
        if ($answerSdp === '') {
            throw new RuntimeException('OpenAI realtime call response did not include an SDP answer.');
        }

        return [
            'provider' => 'openai_realtime',
            'model' => $model,
            'voice' => $voice,
            'voice_label' => self::VOICES[$voice],
            'sdp' => $answerSdp,
            'session_id' => $this->providerSessionId($response->header('Location')),
            'tools' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function realtimeSessionConfiguration(
        AgentProfile $profile,
        array $context,
        string $voice,
        string $model,
    ): array {
        return [
            'type' => 'realtime',
            'model' => $model,
            'instructions' => $this->realtimeInstructions($profile, $context),
            'audio' => [
                'input' => [
                    // Browser Voice v2 appends activated microphone PCM over
                    // the data channel. WebRTC remains receive-only so dormant
                    // room audio has no provider media path.
                    'format' => [
                        'type' => 'audio/pcm',
                        'rate' => 24000,
                    ],
                    'transcription' => [
                        'model' => (string) config('services.openai.realtime_transcription_model', 'gpt-4o-mini-transcribe'),
                        'language' => 'en',
                        'prompt' => 'The user speaks US English. Transcribe only words actually spoken. Do not insert words for silence, music, or background noise. Product names may include Bean and HeyBean.',
                    ],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'threshold' => (float) config('services.openai.realtime_vad_threshold', 0.5),
                        'prefix_padding_ms' => (int) config('services.openai.realtime_vad_prefix_padding_ms', 300),
                        'silence_duration_ms' => (int) config('services.openai.realtime_vad_silence_duration_ms', 2000),
                        'create_response' => false,
                        'interrupt_response' => false,
                    ],
                ],
                'output' => [
                    'voice' => $voice,
                ],
            ],
            'tools' => [],
            'tool_choice' => 'none',
        ];
    }

    private function providerSessionId(?string $location): ?string
    {
        if (! is_string($location) || trim($location) === '') {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH);
        $candidate = basename(is_string($path) ? $path : $location);

        return $candidate !== '' ? $candidate : null;
    }

    public function normalizeVoice(?string $voice): string
    {
        $key = strtolower(trim((string) $voice));

        return array_key_exists($key, self::VOICES) ? $key : self::DEFAULT_VOICE;
    }

    private function realtimeInstructions(AgentProfile $profile, array $context): string
    {
        $beanName = trim((string) ($profile->display_name ?: 'Bean')) ?: 'Bean';

        return trim("You are the US English transcription and speech surface for {$beanName} browser voice. The application owns wake detection, conversation state, request routing, tools, persistence, Stop, cancellation, and final response text. Never call tools or independently answer microphone input. Transcribe only words actually spoken, and do not invent speech from silence, music, background noise, or your own output. When the application explicitly asks you to speak supplied Bean text, speak it exactly, naturally, and without adding facts, questions, or tasks. Keep dates and times naturally spoken in US English.");
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
