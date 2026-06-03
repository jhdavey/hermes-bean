<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AiUsageService
{
    public function __construct(
        private readonly AdminSettingsService $adminSettings,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    /**
     * @return array{allowed:bool,reason:?string,input_tokens:int,reserved_output_tokens:int,estimated_cost_usd:float,budget:array<string,mixed>}
     */
    public function preflight(ConversationSession $session, ConversationMessage $message, array $modelRoute, string $prompt): array
    {
        $user = User::findOrFail($session->user_id);
        $inputTokens = $this->estimateTokens($prompt);
        $reservedOutputTokens = (int) config('services.ai_usage.reserve_output_tokens', 1200);
        $billingModel = (string) ($modelRoute['billing_model'] ?? $modelRoute['model'] ?? $this->adminSettings->mainModel());
        $estimatedCost = $this->estimatedCost($billingModel, $inputTokens, $reservedOutputTokens);

        return $this->preflightDirect(
            $user,
            $session->workspace_id,
            $billingModel,
            $inputTokens,
            $reservedOutputTokens,
            $estimatedCost,
            $this->requestTypeForMessage($message),
            [
                'session' => $session,
                'message' => $message,
                'model_route' => $modelRoute,
            ],
        );
    }

    /**
     * @return array{allowed:bool,reason:?string,input_tokens:int,reserved_output_tokens:int,estimated_cost_usd:float,budget:array<string,mixed>}
     */
    public function preflightDirect(
        User $user,
        ?int $workspaceId,
        string $model,
        int $inputTokens = 0,
        int $reservedOutputTokens = 0,
        ?float $estimatedCost = null,
        string $requestType = 'agent',
        array $context = [],
    ): array {
        if (! $user->isAdmin() && ! $this->adminSettings->beanChatEnabled() && ! in_array($requestType, ['voice_turn', 'realtime_voice', 'tts'], true)) {
            return $this->blockedPreflight($inputTokens, $reservedOutputTokens, $estimatedCost ?? 0.0, $this->beanPausedMessage('chat'), $this->budgetFor($user));
        }

        if (! $user->isAdmin() && ! $this->adminSettings->beanVoiceEnabled() && in_array($requestType, ['voice_session', 'voice_turn', 'realtime_voice', 'tts'], true)) {
            return $this->blockedPreflight($inputTokens, $reservedOutputTokens, $estimatedCost ?? 0.0, $this->beanPausedMessage('voice'), $this->budgetFor($user));
        }

        $estimatedCost ??= $this->estimatedCost($model, $inputTokens, $reservedOutputTokens);
        $budget = $this->budgetFor($user);
        $daily = $this->usageTotals($user->id, null, now()->startOfDay());
        $dailyCounts = $this->dailyRequestCounts($user->id);
        $projectedDailyCost = $daily['cost'] + $estimatedCost;
        $projectedDailyExternalCost = (float) ($dailyCounts['external_cost'] ?? 0)
            + ($this->isExternalRequestType($requestType) ? $estimatedCost : 0.0);

        foreach ([
            ['daily_cost_limit', $projectedDailyCost, 'daily_cost_hard_limit'],
            ['daily_external_cost_limit', $projectedDailyExternalCost, 'daily_external_cost_hard_limit'],
        ] as [$key, $observed, $type]) {
            $threshold = (float) ($budget[$key] ?? 0);
            if ($threshold > 0 && $observed > $threshold) {
                $reason = $this->humanLimitReason($type, $observed, $threshold);
                $this->alertFromContext($user, $workspaceId, $context, $type, 'critical', $threshold, $observed, $reason, [
                    'model' => $model,
                    'estimated_cost_usd' => $estimatedCost,
                ]);

                return $this->blockedPreflight($inputTokens, $reservedOutputTokens, $estimatedCost, $reason, $budget);
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'input_tokens' => $inputTokens,
            'reserved_output_tokens' => $reservedOutputTokens,
            'estimated_cost_usd' => $estimatedCost,
            'budget' => $budget,
        ];
    }

    public function recordCompletion(
        ConversationSession $session,
        ConversationMessage $userMessage,
        ?ConversationMessage $assistantMessage,
        array $modelRoute,
        string $prompt,
        string $stdout,
        Collection $domainEvents,
        string $status = 'completed'
    ): AiUsageLog {
        $inputTokens = $this->estimateTokens($prompt);
        $outputTokens = $this->estimateTokens($stdout);
        $actualUsage = $this->tokenUsageFromPayload($stdout);
        if ($actualUsage['input_tokens'] > 0 || $actualUsage['output_tokens'] > 0) {
            $inputTokens = $actualUsage['input_tokens'];
            $outputTokens = $actualUsage['output_tokens'];
        }
        $displayModel = (string) ($modelRoute['model'] ?? 'agent-routed');
        $billingModel = (string) ($modelRoute['billing_model'] ?? $modelRoute['model'] ?? $this->adminSettings->mainModel());
        $cost = $this->estimatedCostWithAudio($billingModel, $inputTokens, $outputTokens, $actualUsage['audio_input_tokens'], $actualUsage['audio_output_tokens']);
        $actionTypes = $domainEvents
            ->map(fn (ActivityEvent $event): ?string => $event->tool_name ?: $event->event_type)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $log = AiUsageLog::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $userMessage->id,
            'provider' => (string) config('services.hermes_runtime.default_provider'),
            'model' => $displayModel,
            'route_tier' => (string) $modelRoute['tier'],
            'request_type' => $this->requestTypeForMessage($userMessage),
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'audio_input_tokens' => $actualUsage['audio_input_tokens'],
            'audio_output_tokens' => $actualUsage['audio_output_tokens'],
            'total_tokens' => $inputTokens + $outputTokens,
            'tool_call_count' => count($actionTypes),
            'estimated_cost_usd' => $cost,
            'action_types' => $actionTypes,
            'metadata' => [
                'assistant_message_id' => $assistantMessage?->id,
                'model_route' => $modelRoute,
                'billing_model' => $billingModel,
                'context_mode' => $modelRoute['context_mode'] ?? null,
                'input_prompt' => $prompt,
            ],
        ]);

        $this->detectSpikes($session);

        return $log;
    }

    public function recordBlocked(
        ConversationSession $session,
        ConversationMessage $userMessage,
        array $modelRoute,
        array $budget,
        string $reason
    ): AiUsageLog {
        $inputTokens = (int) ($budget['input_tokens'] ?? 0);
        $reservedOutputTokens = (int) ($budget['reserved_output_tokens'] ?? 0);

        return AiUsageLog::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $userMessage->id,
            'provider' => (string) config('services.hermes_runtime.default_provider'),
            'model' => (string) ($modelRoute['model'] ?? 'agent-routed'),
            'route_tier' => (string) $modelRoute['tier'],
            'request_type' => $this->requestTypeForMessage($userMessage),
            'status' => 'blocked',
            'input_tokens' => $inputTokens,
            'output_tokens' => 0,
            'total_tokens' => $inputTokens,
            'tool_call_count' => 0,
            'estimated_cost_usd' => 0,
            'action_types' => [],
            'metadata' => [
                'reason' => $reason,
                'model_route' => $modelRoute,
                'context_mode' => $modelRoute['context_mode'] ?? null,
                'reserved_output_tokens' => $reservedOutputTokens,
                'estimated_blocked_cost_usd' => $budget['estimated_cost_usd'] ?? null,
            ],
        ]);
    }

    public function estimatedCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = $this->pricingFor($model);

        return round((($inputTokens / 1_000_000) * $pricing['input']) + (($outputTokens / 1_000_000) * $pricing['output']), 6);
    }

    public function estimatedCostWithAudio(string $model, int $inputTokens, int $outputTokens, int $audioInputTokens = 0, int $audioOutputTokens = 0): float
    {
        $pricing = $this->pricingFor($model);

        return round(
            (($inputTokens / 1_000_000) * $pricing['input'])
            + (($outputTokens / 1_000_000) * $pricing['output'])
            + (($audioInputTokens / 1_000_000) * ($pricing['audio_input'] ?? $pricing['input']))
            + (($audioOutputTokens / 1_000_000) * ($pricing['audio_output'] ?? $pricing['output'])),
            6,
        );
    }

    public function recordDirectCall(
        User $user,
        ?int $workspaceId,
        string $requestType,
        string $model,
        array $usage = [],
        array $metadata = [],
        array $actionTypes = [],
        string $status = 'completed',
    ): AiUsageLog {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $audioInputTokens = (int) ($usage['audio_input_tokens'] ?? 0);
        $audioOutputTokens = (int) ($usage['audio_output_tokens'] ?? 0);
        $toolCallCount = (int) ($usage['tool_call_count'] ?? count($actionTypes));
        $cost = $this->estimatedCostWithAudio($model, $inputTokens, $outputTokens, $audioInputTokens, $audioOutputTokens);

        $log = AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
            'conversation_message_id' => $metadata['conversation_message_id'] ?? null,
            'provider' => (string) ($metadata['provider'] ?? config('services.hermes_runtime.default_provider')),
            'model' => $model,
            'route_tier' => (string) ($metadata['route_tier'] ?? $requestType),
            'request_type' => $requestType,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'audio_input_tokens' => $audioInputTokens,
            'audio_output_tokens' => $audioOutputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'tool_call_count' => $toolCallCount,
            'estimated_cost_usd' => $cost,
            'action_types' => array_values(array_filter($actionTypes)),
            'metadata' => $metadata ?: null,
        ]);

        return $log;
    }

    /**
     * @return array{input_tokens:int,output_tokens:int,audio_input_tokens:int,audio_output_tokens:int}
     */
    public function usageFromOpenAiResponse(array $response): array
    {
        $usage = (array) ($response['usage'] ?? []);

        return [
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            'audio_input_tokens' => (int) data_get($usage, 'input_token_details.audio_tokens', data_get($usage, 'prompt_tokens_details.audio_tokens', 0)),
            'audio_output_tokens' => (int) data_get($usage, 'output_token_details.audio_tokens', data_get($usage, 'completion_tokens_details.audio_tokens', 0)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function budgetFor(User $user): array
    {
        return $this->planLimits->budgetFor($user);
    }

    /**
     * @return array{actions:int,tokens:int,cost:float}
     */
    public function usageTotals(int $userId, ?int $workspaceId, mixed $since): array
    {
        $query = AiUsageLog::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->where('status', 'completed');

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        return [
            'actions' => (int) (clone $query)->count(),
            'tokens' => (int) (clone $query)->sum('total_tokens'),
            'cost' => (float) (clone $query)->sum('estimated_cost_usd'),
        ];
    }

    /**
     * @return array{text_requests:int,voice_turns:int,voice_seconds:float,external_tool_calls:int,web_search_calls:int,external_cost:float}
     */
    public function dailyRequestCounts(int $userId): array
    {
        $logs = AiUsageLog::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfDay())
            ->get(['request_type', 'action_types', 'metadata', 'estimated_cost_usd']);

        return [
            'text_requests' => $logs->where('request_type', 'text')->count(),
            'voice_turns' => $logs->whereIn('request_type', ['voice_turn', 'realtime_voice'])->count(),
            'voice_seconds' => (float) $logs->sum(fn (AiUsageLog $log): float => (float) data_get($log->metadata ?? [], 'voice_seconds', 0)),
            'external_tool_calls' => $logs->filter(fn (AiUsageLog $log): bool => $this->isExternalUsageLog($log))->count(),
            'web_search_calls' => $logs->filter(fn (AiUsageLog $log): bool => collect($log->action_types ?? [])->contains('web_search'))->count(),
            'external_cost' => (float) $logs->filter(fn (AiUsageLog $log): bool => $this->isExternalUsageLog($log))->sum('estimated_cost_usd'),
        ];
    }

    public function alert(
        ConversationSession $session,
        string $type,
        string $severity,
        float $threshold,
        float $observed,
        string $message,
        array $metadata = [],
        ?string $scopeType = null,
        ?int $scopeId = null
    ): ?AiUsageAlert {
        $scopeType ??= $session->workspace_id ? 'workspace' : 'user';
        $scopeId ??= $session->workspace_id ?: $session->user_id;

        $exists = AiUsageAlert::query()
            ->where('alert_type', $type)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        Log::warning('AI usage alert: '.$message, ['session_id' => $session->id, ...$metadata]);

        return AiUsageAlert::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'alert_type' => $type,
            'severity' => $severity,
            'period_start' => now()->startOfDay()->toDateString(),
            'period_end' => now()->endOfDay()->toDateString(),
            'threshold_value' => $threshold,
            'observed_value' => $observed,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function detectSpikes(ConversationSession $session): void
    {
        $this->detectCostSpike($session, 'user', $session->user_id);
        if ($session->workspace_id) {
            $this->detectCostSpike($session, 'workspace', $session->workspace_id);
        }
    }

    private function detectCostSpike(ConversationSession $session, string $scopeType, int $scopeId): void
    {
        $column = $scopeType === 'workspace' ? 'workspace_id' : 'user_id';
        $todayCost = (float) AiUsageLog::query()
            ->where($column, $scopeId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('estimated_cost_usd');
        $minCost = (float) config('services.ai_usage.spike_min_daily_cost_usd', 1.00);
        if ($todayCost < $minCost) {
            return;
        }

        $priorSevenDayCost = (float) AiUsageLog::query()
            ->where($column, $scopeId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [now()->subDays(7)->startOfDay(), now()->subDay()->endOfDay()])
            ->sum('estimated_cost_usd');
        $dailyAverage = max(0.01, $priorSevenDayCost / 7);
        $multiplier = (float) config('services.ai_usage.spike_multiplier', 3);
        if ($todayCost >= ($dailyAverage * $multiplier)) {
            $this->alert(
                $session,
                $scopeType.'_cost_spike',
                'warning',
                round($dailyAverage * $multiplier, 4),
                round($todayCost, 4),
                ucfirst($scopeType).' AI cost is unusually high today.',
                ['daily_average_usd' => round($dailyAverage, 4), 'multiplier' => $multiplier],
                $scopeType,
                $scopeId
            );
        }
    }

    /**
     * @return array{input:float,output:float}
     */
    private function pricingFor(string $model): array
    {
        $prices = config('services.ai_usage.pricing_per_million', []);
        if (isset($prices[$model])) {
            return [
                'input' => (float) $prices[$model]['input'],
                'output' => (float) $prices[$model]['output'],
                'audio_input' => isset($prices[$model]['audio_input']) ? (float) $prices[$model]['audio_input'] : null,
                'audio_output' => isset($prices[$model]['audio_output']) ? (float) $prices[$model]['audio_output'] : null,
            ];
        }

        foreach ($prices as $knownModel => $price) {
            if (str_contains($model, (string) $knownModel)) {
                return [
                    'input' => (float) $price['input'],
                    'output' => (float) $price['output'],
                    'audio_input' => isset($price['audio_input']) ? (float) $price['audio_input'] : null,
                    'audio_output' => isset($price['audio_output']) ? (float) $price['audio_output'] : null,
                ];
            }
        }

        return ['input' => 5.00, 'output' => 30.00];
    }

    private function blockedPreflight(int $inputTokens, int $reservedOutputTokens, float $estimatedCost, string $reason, array $budget): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'input_tokens' => $inputTokens,
            'reserved_output_tokens' => $reservedOutputTokens,
            'estimated_cost_usd' => $estimatedCost,
            'budget' => $budget,
        ];
    }

    private function requestTypeForMessage(ConversationMessage $message): string
    {
        $metadata = $message->metadata ?? [];
        if ((string) data_get($metadata, 'source') === 'realtime' || data_get($metadata, 'voice_context.mode') === 'live_voice') {
            return 'voice_background';
        }

        return 'text';
    }

    private function alertFromContext(User $user, ?int $workspaceId, array $context, string $type, string $severity, float $threshold, float $observed, string $message, array $metadata = []): void
    {
        $session = $context['session'] ?? null;
        if ($session instanceof ConversationSession) {
            $this->alert($session, $type, $severity, $threshold, $observed, $message, $metadata, 'user', $user->id);

            return;
        }

        $stubSession = new ConversationSession([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
        ]);
        $this->alert($stubSession, $type, $severity, $threshold, $observed, $message, $metadata, 'user', $user->id);
    }

    private function tokenUsageFromPayload(string $payload): array
    {
        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'audio_input_tokens' => 0,
            'audio_output_tokens' => 0,
        ];
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $usage;
        }

        foreach ((array) $decoded as $item) {
            $itemUsage = is_array($item) ? (array) ($item['usage'] ?? []) : [];
            $usage['input_tokens'] += (int) ($itemUsage['prompt_tokens'] ?? $itemUsage['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($itemUsage['completion_tokens'] ?? $itemUsage['output_tokens'] ?? 0);
            $usage['audio_input_tokens'] += (int) data_get($itemUsage, 'input_token_details.audio_tokens', data_get($itemUsage, 'prompt_tokens_details.audio_tokens', 0));
            $usage['audio_output_tokens'] += (int) data_get($itemUsage, 'output_token_details.audio_tokens', data_get($itemUsage, 'completion_tokens_details.audio_tokens', 0));
        }

        return $usage;
    }

    private function beanPausedMessage(string $kind): string
    {
        return $kind === 'voice'
            ? 'Bean voice is paused right now.'
            : 'Bean chat is paused right now.';
    }

    private function humanLimitReason(string $type, float $observed, float $threshold): string
    {
        return match ($type) {
            'daily_cost_hard_limit' => 'This account has reached today\'s AI usage limit.',
            'daily_external_cost_hard_limit' => 'This account has reached today\'s external lookup usage limit.',
            default => "AI usage limit reached ({$observed} / {$threshold}).",
        };
    }

    private function isExternalRequestType(string $requestType): bool
    {
        return in_array($requestType, ['external_lookup', 'web_search'], true);
    }

    private function isExternalUsageLog(AiUsageLog $log): bool
    {
        if ($this->isExternalRequestType((string) $log->request_type)) {
            return true;
        }

        return collect($log->action_types ?? [])
            ->contains(fn (string $action): bool => in_array($action, ['external_lookup', 'open_meteo_weather', 'web_search'], true));
    }
}
