<?php

namespace App\Services\Bean;

use App\Models\BeanRun;
use App\Models\BeanUsageRecord;
use App\Models\BeanVoiceEvent;
use Illuminate\Support\Carbon;

class BeanUsageMeterService
{
    public function recordOpenAiRun(BeanRun $run): ?BeanUsageRecord
    {
        if (! $run->exists || $run->completed_at === null) {
            return null;
        }

        $model = $this->openAiModelName((string) ($run->model ?: config('bean.hermes.model', 'gpt-4.1-mini')));
        $input = (string) ($run->input ?? '');
        $output = (string) ($run->output ?? '');
        $inputTokens = $this->estimateTokens($input);
        $outputTokens = $this->estimateTokens($output);
        $totalTokens = $inputTokens + $outputTokens;
        $rates = $this->openAiRates($model);
        $cost = (($inputTokens * (float) ($rates['input_per_1m'] ?? 0)) + ($outputTokens * (float) ($rates['output_per_1m'] ?? 0))) / 1_000_000;
        $metadata = is_array($run->metadata) ? $run->metadata : [];

        return BeanUsageRecord::updateOrCreate(
            [
                'provider' => 'openai',
                'usage_type' => 'llm_tokens',
                'external_id' => 'bean-run-'.$run->id,
            ],
            [
                'user_id' => $run->user_id,
                'workspace_id' => $run->workspace_id,
                'bean_session_id' => $run->bean_session_id,
                'bean_run_id' => $run->id,
                'bean_voice_event_id' => null,
                'service' => 'bean_hermes',
                'model' => $model,
                'source' => $metadata['source'] ?? null,
                'unit' => 'tokens',
                'quantity' => $totalTokens,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'credits' => null,
                'estimated_cost_usd' => round($cost, 6),
                'is_estimate' => true,
                'metadata' => [
                    'estimation_method' => 'character_count_divided_by_4',
                    'input_characters' => mb_strlen($input),
                    'output_characters' => mb_strlen($output),
                    'input_cost_per_1m_tokens_usd' => (float) ($rates['input_per_1m'] ?? 0),
                    'output_cost_per_1m_tokens_usd' => (float) ($rates['output_per_1m'] ?? 0),
                    'actual_provider_usage_available' => false,
                ],
                'recorded_at' => $run->completed_at ?? now(),
            ]
        );
    }

    public function recordElevenLabsVoiceSession(BeanVoiceEvent $closeEvent): ?BeanUsageRecord
    {
        if (! in_array($closeEvent->event_type, ['voice_session_closed', 'voice_idle_timeout_closed', 'dismiss_closed'], true)) {
            return null;
        }

        $startEvent = $this->matchingVoiceStartEvent($closeEvent);
        if (! $startEvent) {
            return null;
        }

        $durationSeconds = $this->durationSeconds($startEvent, $closeEvent);
        if ($durationSeconds <= 0) {
            return null;
        }

        $conversationId = $this->conversationId($closeEvent) ?: $this->conversationId($startEvent);
        $externalId = $conversationId ?: 'bean-voice-'.$startEvent->id.'-'.$closeEvent->id;
        $costPerMinute = (float) config('bean.usage.elevenlabs_agent_cost_per_minute_usd', 0.08);
        $creditsPerMinute = (float) config('bean.usage.elevenlabs_agent_credits_per_minute', 10000 / 15);
        $minutes = $durationSeconds / 60;

        return BeanUsageRecord::updateOrCreate(
            [
                'provider' => 'elevenlabs',
                'usage_type' => 'voice_session',
                'external_id' => $externalId,
            ],
            [
                'user_id' => $closeEvent->user_id,
                'workspace_id' => null,
                'bean_session_id' => $closeEvent->bean_session_id ?? $startEvent->bean_session_id,
                'bean_run_id' => $closeEvent->bean_run_id ?? $startEvent->bean_run_id,
                'bean_voice_event_id' => $closeEvent->id,
                'service' => 'conversational_ai_agent',
                'model' => null,
                'source' => $closeEvent->source ?: data_get($closeEvent->payload, 'transport', 'elevenlabs_agent'),
                'unit' => 'seconds',
                'quantity' => round($durationSeconds, 4),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'credits' => round($minutes * $creditsPerMinute, 4),
                'estimated_cost_usd' => round($minutes * $costPerMinute, 6),
                'is_estimate' => true,
                'metadata' => [
                    'estimation_method' => 'voice_session_elapsed_seconds_times_configured_agent_rate',
                    'conversation_id' => $conversationId,
                    'started_event_id' => $startEvent->id,
                    'closed_event_id' => $closeEvent->id,
                    'started_at' => $startEvent->occurred_at?->toIso8601String(),
                    'closed_at' => $closeEvent->occurred_at?->toIso8601String(),
                    'max_duration_seconds_configured' => (int) config('bean.usage.elevenlabs_max_duration_seconds', 60),
                    'silence_timeout_seconds_configured' => (int) config('bean.usage.elevenlabs_silence_timeout_seconds', 5),
                    'cost_per_minute_usd' => $costPerMinute,
                    'credits_per_minute' => $creditsPerMinute,
                    'actual_provider_usage_available' => false,
                ],
                'recorded_at' => $closeEvent->occurred_at ?? $closeEvent->created_at ?? now(),
            ]
        );
    }

    private function matchingVoiceStartEvent(BeanVoiceEvent $closeEvent): ?BeanVoiceEvent
    {
        $conversationId = $this->conversationId($closeEvent);
        $query = BeanVoiceEvent::query()
            ->where('user_id', $closeEvent->user_id)
            ->where('event_type', 'voice_session_started')
            ->where('id', '<', $closeEvent->id)
            ->latest('id');

        if ($closeEvent->bean_session_id !== null) {
            $query->where('bean_session_id', $closeEvent->bean_session_id);
        }

        if ($conversationId !== null) {
            $matched = (clone $query)
                ->where('payload->conversation_id', $conversationId)
                ->first();
            if ($matched) {
                return $matched;
            }
        }

        return $query->first();
    }

    private function conversationId(BeanVoiceEvent $event): ?string
    {
        $value = data_get($event->payload, 'conversation_id')
            ?? data_get($event->payload, 'conversationId')
            ?? data_get($event->payload, 'elevenlabs_conversation_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function durationSeconds(BeanVoiceEvent $start, BeanVoiceEvent $end): float
    {
        if ($start->occurred_at_ms !== null && $end->occurred_at_ms !== null && $end->occurred_at_ms >= $start->occurred_at_ms) {
            return max(0, ($end->occurred_at_ms - $start->occurred_at_ms) / 1000);
        }

        $startedAt = $start->occurred_at ?: $start->created_at;
        $endedAt = $end->occurred_at ?: $end->created_at;
        if (! $startedAt instanceof Carbon || ! $endedAt instanceof Carbon || $endedAt->lt($startedAt)) {
            return 0;
        }

        return max(0, $startedAt->floatDiffInSeconds($endedAt));
    }

    private function estimateTokens(string $text): int
    {
        $characters = mb_strlen($text);

        return $characters === 0 ? 0 : (int) ceil($characters / 4);
    }

    private function openAiModelName(string $label): string
    {
        $label = trim($label);
        if (str_contains($label, '/')) {
            return trim(substr($label, strrpos($label, '/') + 1));
        }
        if (str_starts_with($label, 'hermes:')) {
            return trim(substr($label, strlen('hermes:')));
        }

        return $label !== '' ? $label : 'gpt-4.1-mini';
    }

    private function openAiRates(string $model): array
    {
        $prices = config('bean.usage.openai_model_prices', []);
        if (is_array($prices) && isset($prices[$model]) && is_array($prices[$model])) {
            return $prices[$model];
        }

        return is_array($prices) && isset($prices['gpt-4.1-mini']) && is_array($prices['gpt-4.1-mini'])
            ? $prices['gpt-4.1-mini']
            : ['input_per_1m' => 0.40, 'output_per_1m' => 1.60];
    }
}
