<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TextToSpeechController extends Controller
{
    private const OPENAI_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];

    private const LEGACY_VOICE_MAP = [
        'nova' => 'shimmer',
        'onyx' => 'ash',
        'fable' => 'ballad',
    ];

    public function store(Request $request): mixed
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'voice' => ['sometimes', 'string', Rule::in(self::OPENAI_VOICES)],
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $user = $request->user();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
        $settings = $profile->settings ?? [];
        $tts = is_array($settings['tts'] ?? null) ? $settings['tts'] : [];
        $keyCandidates = $this->apiKeyCandidates($tts);

        if ($keyCandidates === []) {
            return response()->json([
                'message' => 'OpenAI text-to-speech is not configured for this workspace.',
                'code' => 'openai_tts_not_configured',
                'workspace_id' => $workspace->id,
            ], 409);
        }

        $response = null;
        $keySource = '';
        $payload = [
            'model' => 'gpt-4o-mini-tts',
            'voice' => $this->openAiVoice((string) ($data['voice'] ?? $tts['openai_voice'] ?? 'coral')),
            'input' => str($data['text'])->squish()->limit(2000, '')->toString(),
            'instructions' => (string) ($tts['openai_instructions'] ?? 'Speak naturally, warmly, and concisely as Bean.'),
            'response_format' => 'wav',
        ];

        foreach ($keyCandidates as $candidate) {
            $keySource = $candidate['source'];
            $response = Http::withToken($candidate['key'])
                ->asJson()
                ->accept('audio/wav')
                ->timeout(25)
                ->post('https://api.openai.com/v1/audio/speech', $payload);

            if ($response->successful()) {
                break;
            }

            Log::warning('OpenAI text-to-speech failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'key_source' => $keySource,
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->toString(),
            ]);
        }

        if (! $response?->successful()) {
            return response()->json([
                'message' => 'OpenAI text-to-speech failed. Check the saved API key, app API key, or billing.',
                'code' => 'openai_tts_failed',
                'status' => $response?->status(),
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'audio/wav',
            'Cache-Control' => 'no-store',
            'X-HeyBean-TTS-Provider' => 'openai',
            'X-HeyBean-TTS-Workspace' => (string) $workspace->id,
            'X-HeyBean-TTS-Voice' => $payload['voice'],
            'X-HeyBean-TTS-Key-Source' => $keySource,
        ]);
    }

    /**
     * @param  array<string, mixed>  $tts
     * @return array<int, array{source:string,key:string}>
     */
    private function apiKeyCandidates(array $tts): array
    {
        $candidates = [];
        $seen = [];
        $encryptedKey = (string) ($tts['openai_api_key_encrypted'] ?? '');

        if ($encryptedKey !== '') {
            try {
                $workspaceKey = trim(Crypt::decryptString($encryptedKey));
                if ($workspaceKey !== '') {
                    $candidates[] = ['source' => 'workspace', 'key' => $workspaceKey];
                    $seen[$workspaceKey] = true;
                }
            } catch (\Throwable) {
                Log::warning('Saved OpenAI text-to-speech key could not be decrypted.');
            }
        }

        $appKey = trim((string) config('services.openai.server_api_key', ''));
        if ($appKey !== '' && ! isset($seen[$appKey])) {
            $candidates[] = ['source' => 'app', 'key' => $appKey];
        }

        return $candidates;
    }

    private function openAiVoice(string $voice): string
    {
        $normalized = strtolower(trim($voice));
        $mapped = self::LEGACY_VOICE_MAP[$normalized] ?? $normalized;

        return in_array($mapped, self::OPENAI_VOICES, true) ? $mapped : 'coral';
    }
}
