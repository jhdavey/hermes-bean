<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class TextToSpeechController extends Controller
{
    public function store(Request $request): mixed
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'voice' => ['sometimes', 'string', Rule::in(['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'])],
        ]);

        $user = $request->user();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
        $settings = $profile->settings ?? [];
        $tts = is_array($settings['tts'] ?? null) ? $settings['tts'] : [];
        $encryptedKey = (string) ($tts['openai_api_key_encrypted'] ?? '');

        if (($tts['provider'] ?? 'browser') !== 'openai' || $encryptedKey === '') {
            return response()->json(['message' => 'OpenAI text-to-speech is not configured.'], 409);
        }

        try {
            $apiKey = Crypt::decryptString($encryptedKey);
        } catch (\Throwable) {
            return response()->json(['message' => 'The saved OpenAI API key could not be read. Save it again in Bean preferences.'], 422);
        }

        $response = Http::withToken($apiKey)
            ->accept('audio/mpeg')
            ->asJson()
            ->timeout(25)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => 'gpt-4o-mini-tts',
                'voice' => (string) ($data['voice'] ?? $tts['openai_voice'] ?? 'coral'),
                'input' => str($data['text'])->squish()->limit(2000, '')->toString(),
                'instructions' => (string) ($tts['openai_instructions'] ?? 'Speak naturally, warmly, and concisely as Bean.'),
                'response_format' => 'mp3',
            ]);

        if (! $response->successful()) {
            return response()->json([
                'message' => 'OpenAI text-to-speech failed. Browser speech will be used instead.',
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ]);
    }
}
