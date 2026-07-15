<?php

namespace App\Services;

use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        if (! $user->isAdmin() && ! $this->adminSettings->beanChatEnabled()) {
            return $this->blockedPreflight($inputTokens, $reservedOutputTokens, $estimatedCost ?? 0.0, $this->beanPausedMessage('chat'), $this->budgetFor($user));
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

    /**
     * @return array{allowed:bool,reason:?string,input_tokens:int,reserved_output_tokens:int,estimated_cost_usd:float,budget:array<string,mixed>}
     */
    public function preflightRealtimeSession(User $user, ?int $workspaceId): array
    {
        return $this->preflightDirect(
            $user,
            $workspaceId,
            (string) config('services.openai.realtime_model', OpenAiVoiceService::DEFAULT_REALTIME_MODEL),
            estimatedCost: max(0.000001, (float) config('services.ai_usage.realtime_session_minimum_cost_usd', 0.001)),
            requestType: 'voice_realtime',
        );
    }

    public function recordRealtimeSessionOpened(
        User $user,
        ?int $workspaceId,
        ?string $providerSessionId,
        string $model,
    ): AiUsageLog {
        return AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'usage_session_id' => (string) Str::uuid(),
            'provider' => 'openai',
            'model' => $model,
            'route_tier' => 'voice_realtime',
            'request_type' => 'voice_realtime_session',
            'status' => 'opened',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'tool_call_count' => 0,
            'estimated_cost_usd' => 0,
            'action_types' => ['voice_realtime_session'],
            'metadata' => [
                'provider_session_id' => $providerSessionId,
                'opened_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $usage
     * @return array{log:AiUsageLog,duplicate:bool,availability:array<string,mixed>}
     */
    public function recordRealtimeUsage(
        User $user,
        string $usageSessionId,
        string $providerEventId,
        string $eventType,
        array $usage,
    ): array {
        $sessionLog = AiUsageLog::query()
            ->where('user_id', $user->id)
            ->where('usage_session_id', $usageSessionId)
            ->where('request_type', 'voice_realtime_session')
            ->firstOrFail();
        $eventIdentity = hash('sha256', $usageSessionId.'|'.$providerEventId);
        $normalized = $this->normalizedRealtimeUsage($usage);
        $model = (string) $sessionLog->model;
        $cost = $this->estimatedRealtimeCost($model, $eventType, $normalized);
        $existing = AiUsageLog::query()->createOrFirst(
            ['provider_event_id' => $eventIdentity],
            [
                'user_id' => $user->id,
                'workspace_id' => $sessionLog->workspace_id,
                'provider' => 'openai',
                'model' => $model,
                'route_tier' => 'voice_realtime',
                'request_type' => 'voice_realtime',
                'status' => 'completed',
                'input_tokens' => $normalized['input_tokens'],
                'output_tokens' => $normalized['output_tokens'],
                'total_tokens' => $normalized['total_tokens'],
                'tool_call_count' => 0,
                'estimated_cost_usd' => $cost,
                'action_types' => ['realtime_audio_native'],
                'metadata' => [
                    'usage_session_id' => $sessionLog->usage_session_id,
                    'provider_session_id' => data_get($sessionLog->metadata, 'provider_session_id'),
                    'provider_event_type' => $eventType,
                    'usage' => $normalized,
                ],
            ],
        );
        $duplicate = ! $existing->wasRecentlyCreated;

        $availability = $this->realtimeUsageAvailability($user);
        if (! $availability['allowed']) {
            $this->alertFromContext(
                $user,
                $sessionLog->workspace_id,
                [],
                'daily_cost_hard_limit',
                'critical',
                (float) $availability['limit_usd'],
                (float) $availability['used_usd'],
                (string) $availability['reason'],
                [
                    'lane' => 'voice_realtime',
                    'plan_tier' => $availability['tier'],
                    'usage_session_id' => $usageSessionId,
                    'provider_session_id' => data_get($sessionLog->metadata, 'provider_session_id'),
                ],
            );
        }

        return ['log' => $existing, 'duplicate' => $duplicate, 'availability' => $availability];
    }

    /** @return array{allowed:bool,reason:?string,tier:string,used_usd:float,limit_usd:float|null} */
    public function realtimeUsageAvailability(User $user): array
    {
        $budget = $this->budgetFor($user);
        $limit = $budget['daily_cost_limit'] ?? null;
        $used = $this->usageTotals($user->id, null, now()->startOfDay())['cost'];
        $allowed = $user->isAdmin() || $limit === null || (float) $limit <= 0 || $used < (float) $limit;

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : 'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.',
            'tier' => (string) ($budget['tier'] ?? $user->subscriptionTier()),
            'used_usd' => round($used, 6),
            'limit_usd' => $limit === null ? null : (float) $limit,
        ];
    }

    public function estimatedCost(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = $this->pricingFor($model);

        return round((($inputTokens / 1_000_000) * $pricing['input']) + (($outputTokens / 1_000_000) * $pricing['output']), 6);
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
        ?string $providerEventId = null,
    ): AiUsageLog {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $toolCallCount = (int) ($usage['tool_call_count'] ?? count($actionTypes));
        $cost = $this->estimatedCost($model, $inputTokens, $outputTokens);

        $values = [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
            'conversation_message_id' => $metadata['conversation_message_id'] ?? null,
            'provider' => (string) ($metadata['provider'] ?? 'openai'),
            'model' => $model,
            'route_tier' => (string) ($metadata['route_tier'] ?? $requestType),
            'request_type' => $requestType,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'tool_call_count' => $toolCallCount,
            'estimated_cost_usd' => $cost,
            'action_types' => array_values(array_filter($actionTypes)),
            'metadata' => $metadata ?: null,
        ];

        $log = $providerEventId === null
            ? AiUsageLog::create($values)
            : AiUsageLog::query()->createOrFirst(
                ['provider_event_id' => $providerEventId],
                $values,
            );

        return $log;
    }

    /**
     * @return array{input_tokens:int,output_tokens:int}
     */
    public function usageFromOpenAiResponse(array $response): array
    {
        $usage = (array) ($response['usage'] ?? []);

        return [
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
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
     * @return array{text_requests:int,external_tool_calls:int,web_search_calls:int,external_cost:float}
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

    /** @param array<string,mixed> $usage
     * @return array<string,int>
     */
    private function normalizedRealtimeUsage(array $usage): array
    {
        $inputDetails = (array) ($usage['input_token_details'] ?? []);
        $cachedDetails = (array) ($inputDetails['cached_tokens_details'] ?? []);
        $outputDetails = (array) ($usage['output_token_details'] ?? []);
        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => max($inputTokens + $outputTokens, (int) ($usage['total_tokens'] ?? 0)),
            'input_text_tokens' => max(0, (int) ($inputDetails['text_tokens'] ?? 0)),
            'input_audio_tokens' => max(0, (int) ($inputDetails['audio_tokens'] ?? $inputTokens)),
            'cached_text_tokens' => max(0, (int) ($cachedDetails['text_tokens'] ?? 0)),
            'cached_audio_tokens' => max(0, (int) ($cachedDetails['audio_tokens'] ?? 0)),
            'output_text_tokens' => max(0, (int) ($outputDetails['text_tokens'] ?? $outputTokens)),
            'output_audio_tokens' => max(0, (int) ($outputDetails['audio_tokens'] ?? 0)),
        ];
    }

    /** @param array<string,int> $usage */
    private function estimatedRealtimeCost(string $model, string $eventType, array $usage): float
    {
        $pricing = $this->realtimePricingFor($model);
        $cachedText = min($usage['input_text_tokens'], $usage['cached_text_tokens']);
        $cachedAudio = min($usage['input_audio_tokens'], $usage['cached_audio_tokens']);
        $uncachedText = max(0, $usage['input_text_tokens'] - $cachedText);
        $uncachedAudio = max(0, $usage['input_audio_tokens'] - $cachedAudio);

        return round(
            (($uncachedText / 1_000_000) * ($pricing['text_input'] ?? 0))
            + (($cachedText / 1_000_000) * ($pricing['cached_text_input'] ?? $pricing['text_input'] ?? 0))
            + (($uncachedAudio / 1_000_000) * ($pricing['audio_input'] ?? 0))
            + (($cachedAudio / 1_000_000) * ($pricing['cached_audio_input'] ?? $pricing['audio_input'] ?? 0))
            + (($usage['output_text_tokens'] / 1_000_000) * ($pricing['text_output'] ?? 0))
            + (($usage['output_audio_tokens'] / 1_000_000) * ($pricing['audio_output'] ?? 0)),
            6,
        );
    }

    /** @return array<string,float> */
    private function realtimePricingFor(string $model): array
    {
        $prices = (array) config('services.ai_usage.realtime_pricing_per_million', []);
        if (isset($prices[$model])) {
            return array_map('floatval', (array) $prices[$model]);
        }

        foreach ($prices as $knownModel => $price) {
            if (str_contains($model, (string) $knownModel)) {
                return array_map('floatval', (array) $price);
            }
        }

        return [
            'text_input' => 4.00,
            'cached_text_input' => 0.40,
            'audio_input' => 32.00,
            'cached_audio_input' => 0.40,
            'text_output' => 16.00,
            'audio_output' => 64.00,
        ];
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
            ];
        }

        foreach ($prices as $knownModel => $price) {
            if (str_contains($model, (string) $knownModel)) {
                return [
                    'input' => (float) $price['input'],
                    'output' => (float) $price['output'],
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

    private function beanPausedMessage(string $kind): string
    {
        return 'Bean chat is paused right now.';
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
