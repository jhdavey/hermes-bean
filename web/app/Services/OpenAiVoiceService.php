<?php

namespace App\Services;

use App\Models\AgentProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiVoiceService
{
    public const DEFAULT_VOICE = 'alloy';
    public const SPEECH_MODEL = 'gpt-4o-mini-tts';
    public const TRANSCRIPTION_MODEL = 'gpt-4o-mini-transcribe';

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
            'provider' => 'openai',
            'voice' => $selected,
            'voice_label' => self::VOICES[$selected],
            'speech_model' => self::SPEECH_MODEL,
            'transcription_model' => self::TRANSCRIPTION_MODEL,
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

    public function synthesizeSpeech(string $text, AgentProfile $profile): array
    {
        $input = trim($text);
        if ($input === '') {
            throw new RuntimeException('Speech text cannot be empty.');
        }

        $response = Http::withToken($this->apiKey())
            ->accept('audio/mpeg')
            ->asJson()
            ->timeout((float) config('services.openai.speech_timeout', 20))
            ->post($this->endpoint('/audio/speech'), [
                'model' => (string) config('services.openai.speech_model', self::SPEECH_MODEL),
                'voice' => $this->normalizeVoice(data_get($profile->settings ?? [], 'voice.voice')),
                'input' => mb_substr($input, 0, 4096),
                'response_format' => 'mp3',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI speech request failed with status '.$response->status().'.');
        }

        $voice = $this->normalizeVoice(data_get($profile->settings ?? [], 'voice.voice'));

        return [
            'provider' => 'openai',
            'voice' => $voice,
            'voice_label' => self::VOICES[$voice],
            'mime_type' => $response->header('Content-Type') ?: 'audio/mpeg',
            'audio_base64' => base64_encode($response->body()),
        ];
    }

    public function transcribe(UploadedFile $audio): array
    {
        $response = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout((float) config('services.openai.transcription_timeout', 20))
            ->attach('file', file_get_contents($audio->getRealPath()), $audio->getClientOriginalName() ?: 'voice.webm')
            ->post($this->endpoint('/audio/transcriptions'), [
                'model' => (string) config('services.openai.transcription_model', self::TRANSCRIPTION_MODEL),
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI transcription request failed with status '.$response->status().'.');
        }

        return [
            'provider' => 'openai',
            'text' => trim((string) $response->json('text', '')),
        ];
    }

    public function normalizeVoice(?string $voice): string
    {
        $key = strtolower(trim((string) $voice));

        return array_key_exists($key, self::VOICES) ? $key : self::DEFAULT_VOICE;
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
