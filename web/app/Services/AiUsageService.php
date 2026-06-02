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
    public function __construct(private readonly AdminSettingsService $adminSettings) {}

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
        $budget = $this->budgetFor($user);
        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $daily = $this->usageTotals($session->user_id, null, $today);
        $monthly = $this->usageTotals($session->user_id, null, $month);
        $projectedDailyTokens = $daily['tokens'] + $inputTokens + $reservedOutputTokens;
        $projectedDailyCost = $daily['cost'] + $estimatedCost;
        $projectedMonthlyTokens = $monthly['tokens'] + $inputTokens + $reservedOutputTokens;
        $projectedMonthlyCost = $monthly['cost'] + $estimatedCost;
        $projectedMonthlyActions = $monthly['actions'] + 1;

        foreach ([
            ['monthly_ai_actions', $projectedMonthlyActions, 'monthly_action_limit'],
            ['monthly_tokens', $projectedMonthlyTokens, 'monthly_token_limit'],
            ['monthly_cost_usd', $projectedMonthlyCost, 'monthly_cost_limit'],
            ['daily_hard_tokens', $projectedDailyTokens, 'daily_token_hard_limit'],
            ['daily_hard_cost_usd', $projectedDailyCost, 'daily_cost_hard_limit'],
        ] as [$key, $observed, $type]) {
            $threshold = (float) ($budget[$key] ?? 0);
            if ($threshold > 0 && $observed > $threshold) {
                $reason = $this->humanLimitReason($type, $observed, $threshold);
                $this->alert($session, $type, 'critical', $threshold, $observed, $reason, [
                    'model_route' => $modelRoute,
                    'message_id' => $message->id,
                    'estimated_cost_usd' => $estimatedCost,
                ], 'user', $session->user_id);

                return [
                    'allowed' => false,
                    'reason' => $reason,
                    'input_tokens' => $inputTokens,
                    'reserved_output_tokens' => $reservedOutputTokens,
                    'estimated_cost_usd' => $estimatedCost,
                    'budget' => $budget,
                ];
            }
        }

        foreach ([
            ['daily_soft_tokens', $projectedDailyTokens, 'daily_token_soft_warning'],
            ['daily_soft_cost_usd', $projectedDailyCost, 'daily_cost_soft_warning'],
        ] as [$key, $observed, $type]) {
            $threshold = (float) ($budget[$key] ?? 0);
            if ($threshold > 0 && $observed > $threshold) {
                $this->alert($session, $type, 'warning', $threshold, $observed, $this->humanLimitReason($type, $observed, $threshold), [
                    'model_route' => $modelRoute,
                    'message_id' => $message->id,
                ], 'user', $session->user_id);
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
        $displayModel = (string) ($modelRoute['model'] ?? 'agent-routed');
        $billingModel = (string) ($modelRoute['billing_model'] ?? $modelRoute['model'] ?? $this->adminSettings->mainModel());
        $cost = $this->estimatedCost($billingModel, $inputTokens, $outputTokens);
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
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
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
            'status' => 'blocked',
            'input_tokens' => $inputTokens,
            'output_tokens' => 0,
            'total_tokens' => $inputTokens,
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

    /**
     * @return array<string,mixed>
     */
    public function budgetFor(User $user): array
    {
        if (! $user->isAdmin() && $this->isActiveBetaUser($user)) {
            return $this->adminSettings->betaBudget();
        }

        $tier = $user->isAdmin() ? 'admin' : $user->subscriptionTier();
        $budgets = config('services.ai_usage.budgets', []);

        return (array) ($budgets[$tier] ?? $budgets['free'] ?? []);
    }

    private function isActiveBetaUser(User $user): bool
    {
        if ($user->relationLoaded('betaUser')) {
            return $user->betaUser?->status === 'active';
        }

        return $user->betaUser()->where('status', 'active')->exists();
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
            return ['input' => (float) $prices[$model]['input'], 'output' => (float) $prices[$model]['output']];
        }

        foreach ($prices as $knownModel => $price) {
            if (str_contains($model, (string) $knownModel)) {
                return ['input' => (float) $price['input'], 'output' => (float) $price['output']];
            }
        }

        return ['input' => 5.00, 'output' => 30.00];
    }

    private function humanLimitReason(string $type, float $observed, float $threshold): string
    {
        return match ($type) {
            'monthly_action_limit' => 'This account has reached its monthly Bean action limit.',
            'monthly_token_limit' => 'This account has reached its monthly AI token budget.',
            'monthly_cost_limit' => 'This account has reached its monthly AI usage budget.',
            'daily_token_hard_limit' => 'This account has reached today\'s AI token limit.',
            'daily_cost_hard_limit' => 'This account has reached today\'s AI usage limit.',
            'daily_token_soft_warning' => 'This account is approaching today\'s AI token limit.',
            'daily_cost_soft_warning' => 'This account is approaching today\'s AI usage limit.',
            default => "AI usage limit reached ({$observed} / {$threshold}).",
        };
    }
}
