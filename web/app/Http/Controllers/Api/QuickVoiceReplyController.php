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
            'max_completion_tokens' => (int) config('services.hermes_runtime.quick_reply_max_completion_tokens', 40),
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
            ->limit(360, '')
            ->toString();
        $text = $this->personableVoiceText($text);

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
                'continue_agent' => $stage === 'bridge' || $this->shouldContinueAgent($content),
            ],
        ]);
    }

    private function shouldContinueAgent(string $content): bool
    {
        $command = str($content)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s\']/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if ($command === '') {
            return false;
        }

        if (preg_match('/\b(calendar|calendars|event|events|task|tasks|todo|to do|reminder|reminders|agenda|approval|approvals|workspace|workspaces|google calendar)\b/', $command)) {
            return true;
        }

        if (preg_match('/\b(flight|flights|airfare|airfares|ticket|tickets|hotel|hotels|rental car|rentals|reservation|reservations|booking|bookings|price|prices|cheapest|available|availability|weather|forecast|news|traffic|stock|stocks|sports|score|scores)\b/', $command)) {
            return true;
        }

        if (preg_match('/\b(today|tonight|tomorrow|current|currently|latest|now|right now|near me|nearby|local)\b/', $command)
            && preg_match('/\b(open|opens|closed|closes|close|closing|hours|hour|available|availability|price|prices|cost|costs|status|delay|delays)\b/', $command)) {
            return true;
        }

        if (preg_match('/\b(add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b/', $command)) {
            return true;
        }

        if (preg_match('/\b(plan|organize|prioritize)\b/', $command)
            && preg_match('/\b(day|today|tomorrow|week|schedule|work|tasks|calendar|morning|afternoon|evening)\b/', $command)) {
            return true;
        }

        if (preg_match('/\b(what do i have|what have i got|do i have anything|anything on|what\'?s next|whats next|what is next|next up)\b/', $command)) {
            return true;
        }

        return false;
    }

    private function systemPrompt(string $stage): string
    {
        if ($stage === 'bridge') {
            return <<<'PROMPT'
You are Bean's live voice layer in the Hey Bean app.

Bean already gave an initial spoken response and the main answer is still being prepared.
Generate one brief, natural bridge sentence that tells the user the request is taking longer while staying specific to their request.
Do not answer the user's request. Do not invent facts, calendar details, task results, or completed actions.
Do not repeat or paraphrase anything in voice_turn.spoken_segments.
Avoid generic filler like "Got it", "one second", "still working", "just a moment", or "I'll be right back".
Do not mention tools, models, background jobs, or internal work.
Sound conversational and connected to the request. Keep it under 18 words.
PROMPT;
        }

        return <<<'PROMPT'
You are Bean's live voice layer in the Hey Bean app.

The user has just finished speaking. Give the first natural spoken reply immediately.
Do not use canned support-agent phrases. Do not mention tools, models, background jobs, or internal work.
Sound warm, friendly, and lightly upbeat, like a helpful person who is glad to help. Use natural spoken phrasing with contractions. Prefer "Sure, I'll check Orlando's weather now" over "I will check the weather for Orlando Florida".
It is okay to use one brief friendly opener like "Sure", "Absolutely", or "Yeah" when it sounds natural. Do not overdo enthusiasm, use exclamation marks, or sound salesy.
If the user asks a normal conversational question, answer with a useful first thought right away.
For casual questions, do not start with "Got it"; answer directly.
For casual questions that do not need app data, live external data, or an app change, give a compact complete answer in one or two short sentences.
If the user asks for advice, a recipe, a workout, a plan, a list, or instructions, give a useful first answer and do not ask whether they want you to create the thing they just requested.
If the user asks for current app data, live external data, or an app change, respond naturally with what you are about to check or do, without claiming it is already done.
Finish complete thoughts. Do not end with a comma, colon, or unfinished list.
Keep it under 45 words.
PROMPT;
    }

    private function personableVoiceText(string $text): string
    {
        $text = preg_replace('/\bI will\b/u', "I'll", $text) ?? $text;
        $text = preg_replace('/\bI am\b/u', "I'm", $text) ?? $text;
        $text = preg_replace('/\bI have\b/u', "I've", $text) ?? $text;

        return str($text)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
