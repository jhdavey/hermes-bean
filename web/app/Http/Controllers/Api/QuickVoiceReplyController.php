<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class QuickVoiceReplyController extends Controller
{
    public function store(Request $request, WorkspaceService $workspaces, AgentProfileService $profiles): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:1000'],
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'client_context' => ['sometimes', 'nullable', 'array'],
            'stage' => ['sometimes', 'string', Rule::in(['first', 'bridge'])],
            'spoken_segments' => ['sometimes', 'array', 'max:4'],
            'spoken_segments.*' => ['string', 'max:220'],
            'elapsed_ms' => ['sometimes', 'integer', 'min:0', 'max:60000'],
        ]);

        $apiKey = (string) config('services.hermes_runtime.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Quick voice replies are not configured because the OpenAI API key is missing.',
                'code' => 'openai_quick_voice_not_configured',
            ], 409);
        }

        $user = $request->user();
        $workspace = $workspaces->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $profile = $profiles->ensureForWorkspace($workspace, $user);
        $settings = $profile->settings ?? [];
        $model = (string) config('services.hermes_runtime.quick_reply_model', 'gpt-5.4-mini');
        $content = str($data['content'])->squish()->limit(1000, '')->toString();
        $stage = (string) ($data['stage'] ?? 'first');

        $payload = [
            'model' => $model,
            'max_completion_tokens' => (int) config('services.hermes_runtime.quick_reply_max_completion_tokens', 64),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt($stage),
                ],
                [
                    'role' => 'system',
                    'content' => 'Voice context: '.json_encode([
                        'workspace' => [
                            'id' => $workspace->id,
                            'name' => $workspace->name,
                            'type' => $workspace->type,
                        ],
                        'user' => [
                            'name' => $user->name,
                        ],
                        'agent_profile' => [
                            'personality_type' => data_get($settings, 'personality_type'),
                            'personality_prompt' => data_get($settings, 'personality_prompt'),
                            'onboarding' => data_get($settings, 'onboarding'),
                            'user_preferences' => data_get($settings, 'memory.user_preferences'),
                        ],
                        'client_context' => $data['client_context'] ?? null,
                        'voice_turn' => [
                            'stage' => $stage,
                            'spoken_segments' => array_values($data['spoken_segments'] ?? []),
                            'elapsed_ms' => (int) ($data['elapsed_ms'] ?? 0),
                        ],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout((float) config('services.hermes_runtime.quick_reply_timeout', 4))
                ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/chat/completions', $payload);
        } catch (\Throwable $exception) {
            Log::warning('Quick voice reply request failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Quick voice reply failed.',
                'code' => 'openai_quick_voice_failed',
            ], 502);
        }

        if (! $response->successful()) {
            Log::warning('Quick voice reply failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->toString(),
            ]);

            return response()->json([
                'message' => 'Quick voice reply failed.',
                'code' => 'openai_quick_voice_failed',
                'status' => $response->status(),
            ], 502);
        }

        $text = str((string) data_get($response->json(), 'choices.0.message.content', ''))
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->limit(220, '')
            ->toString();

        if ($text === '') {
            return response()->json([
                'message' => 'Quick voice reply was empty.',
                'code' => 'openai_quick_voice_empty',
            ], 502);
        }

        return response()->json([
            'data' => [
                'text' => $text,
                'model' => $model,
            ],
        ]);
    }

    private function systemPrompt(string $stage): string
    {
        if ($stage === 'bridge') {
            return <<<'PROMPT'
You are Bean's live voice layer in the Hey Bean app.

Bean already gave an initial spoken response and the main answer is still being prepared.
Generate one brief, natural bridge sentence so the pause feels intentional.
Do not answer the user's request. Do not add new advice, facts, calendar details, or task results.
Do not repeat or paraphrase anything in voice_turn.spoken_segments.
Do not mention tools, models, background jobs, or internal work.
Sound conversational, not scripted. Keep it under 14 words.
PROMPT;
        }

        return <<<'PROMPT'
You are Bean's live voice layer in the Hey Bean app.

The user has just finished speaking. Give the first natural spoken reply immediately.
Do not use canned support-agent phrases. Do not mention tools, models, background jobs, or internal work.
If the user asks a normal conversational question, answer with a useful first thought right away.
For casual questions, do not start with "Got it"; answer directly.
If the user asks for current app data or an app change, respond naturally with what you are about to check or do, without claiming it is already done.
Keep it to one sentence under 24 words.
PROMPT;
    }
}
