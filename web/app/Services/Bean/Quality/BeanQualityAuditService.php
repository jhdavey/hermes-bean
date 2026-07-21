<?php

namespace App\Services\Bean\Quality;

use App\Models\BeanQualityTrace;
use App\Models\BeanRun;
use App\Models\BeanToolCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BeanQualityAuditService
{
    public function traceRun(BeanRun $run): BeanQualityTrace
    {
        $run->loadMissing('toolCalls');
        $toolCalls = $run->toolCalls instanceof Collection ? $run->toolCalls : collect();
        $actions = $toolCalls->pluck('action')->filter()->values()->all();
        $timeLabel = $this->timeLabel($toolCalls);
        $intent = $this->intent($run, $actions, $timeLabel);
        $latencyMs = $this->latencyMs($run);
        $flags = $this->qualityFlags(
            (string) ($run->input ?? ''),
            (string) ($run->output ?? ''),
            $toolCalls,
            $intent,
            $timeLabel,
        );

        $trace = BeanQualityTrace::updateOrCreate(
            ['bean_run_id' => $run->id],
            [
                'user_id' => $run->user_id,
                'workspace_id' => $run->workspace_id,
                'mode' => (string) ($run->mode ?: 'text'),
                'intent' => $intent,
                'actions' => $actions,
                'time_label' => $timeLabel,
                'tool_results_count' => $toolCalls->where('status', 'completed')->count(),
                'user_message' => $run->input,
                'assistant_answer' => $run->output,
                'quality_flags' => $flags,
                'latency_ms' => $latencyMs,
                'voice' => (string) $run->mode === 'voice',
            ],
        );

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $metadata['quality_flags'] = $flags;
        $metadata['quality_trace_id'] = $trace->id;
        $run->forceFill(['metadata' => $metadata])->save();

        return $trace->refresh();
    }

    public function auditRecentRuns(int $limit = 200): Collection
    {
        return BeanRun::query()
            ->whereNotNull('completed_at')
            ->latest('id')
            ->limit(max(1, min($limit, 1000)))
            ->get()
            ->map(fn (BeanRun $run): BeanQualityTrace => $this->traceRun($run));
    }

    public function productionSmokeReport(int $recent = 200): array
    {
        $traces = $this->auditRecentRuns($recent);
        $flagCounts = $traces
            ->flatMap(fn (BeanQualityTrace $trace): array => $trace->quality_flags ?? [])
            ->countBy()
            ->sortDesc();
        $intentCounts = $traces->groupBy('intent')->map->count()->sortDesc();
        $flagged = $traces->filter(fn (BeanQualityTrace $trace): bool => count($trace->quality_flags ?? []) > 0)->values();

        return [
            'mode' => 'production-smoke',
            'generated_at' => now()->toIso8601String(),
            'trace_count' => $traces->count(),
            'flagged_trace_count' => $flagged->count(),
            'flagged_rate' => $traces->isEmpty() ? 0.0 : round($flagged->count() / $traces->count(), 4),
            'top_quality_flags' => $flagCounts->keys()->values()->all(),
            'quality_flag_counts' => $flagCounts->all(),
            'intent_counts' => $intentCounts->all(),
            'average_latency_ms' => (int) round($traces->filter(fn ($trace) => $trace->latency_ms !== null)->avg('latency_ms') ?: 0),
            'voice_success_rate' => $this->voiceSuccessRate($traces),
            'recent_flagged_runs' => $flagged->take(20)->map(fn (BeanQualityTrace $trace): array => [
                'bean_run_id' => $trace->bean_run_id,
                'intent' => $trace->intent,
                'actions' => $trace->actions ?? [],
                'time_label' => $trace->time_label,
                'quality_flags' => $trace->quality_flags ?? [],
                'latency_ms' => $trace->latency_ms,
                'voice' => $trace->voice,
                'user_message' => $trace->user_message,
                'assistant_answer' => $trace->assistant_answer,
            ])->values()->all(),
            'guidance' => 'Read-only audit: reports suspicious live Bean answers and never mutates app resources or code.',
        ];
    }

    public function qualityFlags(string $userMessage, string $assistantAnswer, Collection $toolCalls, ?string $intent = null, ?string $timeLabel = null): array
    {
        $flags = [];
        $answer = trim($assistantAnswer);
        $lowerAnswer = mb_strtolower($answer);
        $lowerUser = mb_strtolower($userMessage);
        $actions = $toolCalls->pluck('action')->filter()->values()->all();
        $factual = $this->isFactualQuestion($lowerUser) || $this->hasReadAction($actions);

        if ($factual && preg_match('/\b(i[’\']?ll|i will|checking|check|retrieve|look).*\bdone\.?$/iu', $answer) === 1) {
            $flags[] = 'generic_done_after_factual_question';
        }
        if ($factual && preg_match('/^done\.?$/iu', $answer) === 1) {
            $flags[] = 'generic_done_after_factual_question';
        }
        if ($toolCalls->where('status', 'completed')->isNotEmpty() && $factual && $this->isGenericNoFactAnswer($lowerAnswer)) {
            $flags[] = 'tool_completed_but_no_factual_answer';
        }
        if ($toolCalls->where('status', 'completed')->isEmpty() && $this->isAppDataFactualQuestion($lowerUser) && ! $this->isClarifyingAnswer($lowerAnswer)) {
            $flags[] = 'factual_app_data_answer_without_tool_call';
        }
        if ($this->looksLikeWeatherQuestion($lowerUser) && ! in_array('external.weather', $actions, true) && ! $this->isClarifyingAnswer($lowerAnswer)) {
            $flags[] = 'weather_request_without_external_weather';
        }
        if ($this->looksLikeExternalLookupQuestion($lowerUser) && ! in_array('external.lookup', $actions, true)) {
            $flags[] = 'external_lookup_request_without_external_lookup';
        }
        if ($this->looksLikeSubstantivePublicNoteCreation($lowerUser) && ! in_array('external.lookup', $actions, true)) {
            $flags[] = 'grounded_note_creation_without_external_lookup';
        }
        if (in_array('external.lookup', $actions, true) && ! $this->externalLookupHasSources($toolCalls)) {
            $flags[] = 'external_lookup_completed_without_sources';
        }
        if (preg_match('/\b(source|sources|according to|citation|cited)\b/u', $lowerAnswer) === 1 && in_array('external.lookup', $actions, true) && ! $this->externalLookupHasSources($toolCalls)) {
            $flags[] = 'source_claim_without_external_sources';
        }
        if ($this->looksLikeCorrection($lowerUser) && $toolCalls->where('status', 'completed')->isEmpty()) {
            $flags[] = 'correction_turn_without_recovery_action';
        }
        if ($this->looksLikeReferenceFollowup($lowerUser) && $toolCalls->where('status', 'completed')->isEmpty()) {
            $flags[] = 'immediate_context_loss';
        }
        if ($this->looksLikeReferenceFollowup($lowerUser) && $this->isClarifyingAnswer($lowerAnswer)) {
            $flags[] = 'unnecessary_clarification';
        }
        if (in_array('time.now', $actions, true) && $this->asksTime($lowerUser) && ! preg_match('/\b\d{1,2}:\d{2}\s?(am|pm|utc|est|edt|cst|cdt|mst|mdt|pst|pdt)?\b/i', $answer)) {
            $flags[] = 'missing_time_after_time_tool';
        }
        if (in_array('time.now', $actions, true) && $this->asksDate($lowerUser) && ! $this->answerContainsDate($answer)) {
            $flags[] = 'missing_date_after_time_tool';
        }
        if (in_array('task.list', $actions, true) && $timeLabel === 'today' && $this->todayTaskResultHasOverdue($toolCalls) && ! str_contains($lowerAnswer, 'overdue')) {
            $flags[] = 'today_task_list_missing_overdue_label';
        }
        if ($this->looksLikeTomorrowCalendarQuestion($lowerUser) && in_array('calendar_event.list', $actions, true) && $timeLabel !== 'tomorrow') {
            $flags[] = 'calendar_tomorrow_missing_time_scope';
        }
        if ($this->looksLikeWorkspaceQuestion($lowerUser) && $this->workspaceNamesFromResults($toolCalls) !== []) {
            $workspaceNames = $this->workspaceNamesFromResults($toolCalls);
            $mentioned = collect($workspaceNames)->contains(fn (string $name): bool => str_contains($lowerAnswer, mb_strtolower($name)));
            if (! $mentioned) {
                $flags[] = 'workspace_question_omitted_workspace_name';
            }
        }

        return array_values(array_unique($flags));
    }

    private function timeLabel(Collection $toolCalls): ?string
    {
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof BeanToolCall) {
                continue;
            }
            $arguments = is_array($toolCall->arguments) ? $toolCall->arguments : [];
            $result = is_array($toolCall->result) ? $toolCall->result : [];
            $label = strtolower(trim((string) ($arguments['time_label'] ?? $result['time_label'] ?? '')));
            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    private function intent(BeanRun $run, array $actions, ?string $timeLabel): ?string
    {
        if ($actions !== []) {
            $intent = (string) $actions[0];

            return $timeLabel ? $intent.'.'.$timeLabel : $intent;
        }
        $input = mb_strtolower((string) $run->input);
        if (str_contains($input, 'time')) {
            return 'time.now';
        }
        if (str_contains($input, 'overdue')) {
            return 'overdue.query';
        }
        if (str_contains($input, 'today')) {
            return 'today.query';
        }

        return null;
    }

    private function latencyMs(BeanRun $run): ?int
    {
        if (! $run->started_at || ! $run->completed_at) {
            return null;
        }

        return max(0, (int) Carbon::parse($run->started_at)->diffInMilliseconds(Carbon::parse($run->completed_at)));
    }

    private function isFactualQuestion(string $lowerUser): bool
    {
        return preg_match('/\b(what|which|where|why|when|show|list|do i have|anything|current time|time is it|today|overdue|workspace|workspaces)\b/u', $lowerUser) === 1;
    }

    private function hasReadAction(array $actions): bool
    {
        return collect($actions)->contains(fn (string $action): bool => str_ends_with($action, '.list') || str_ends_with($action, '.search') || in_array($action, ['time.now', 'resource.query', 'resource.relationships', 'dashboard.summary'], true));
    }

    private function isGenericNoFactAnswer(string $lowerAnswer): bool
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $lowerAnswer) ?: $lowerAnswer);
        if (in_array($normalized, ['done.', 'done', 'all done.', 'i’ll check. done.', 'i will check. done.'], true)) {
            return true;
        }

        return preg_match('/\b(i[’\']?ll|i will|checking|check|retrieve|look)\b.*\bdone\.?$/u', $normalized) === 1;
    }

    private function isAppDataFactualQuestion(string $lowerUser): bool
    {
        return preg_match('/\b(what|which|where|why|show|list|find|workspace|workspaces)\b/u', $lowerUser) === 1
            && preg_match('/\b(task|tasks|todo|reminder|reminders|calendar|event|events|note|notes|workspace|workspaces|card|grout|grill)\b/u', $lowerUser) === 1;
    }

    private function looksLikeTomorrowCalendarQuestion(string $lowerUser): bool
    {
        return preg_match('/\b(tomorrow|tmrw|next day)\b/u', $lowerUser) === 1
            && preg_match('/\b(calendar|event|events|meeting|meetings|appointment|appointments|schedule)\b/u', $lowerUser) === 1;
    }

    private function isClarifyingAnswer(string $lowerAnswer): bool
    {
        return preg_match('/\b(which one|which .*\?|which note|which task|what do you mean|can you clarify|please clarify|did you mean|i heard)\b/u', $lowerAnswer) === 1;
    }

    private function looksLikeExternalLookupQuestion(string $lowerUser): bool
    {
        if (preg_match('/\b(time|date|today[’\']?s date|what day|current time|time is it)\b/u', $lowerUser) === 1) {
            return false;
        }
        if ($this->looksLikeWeatherQuestion($lowerUser)) {
            return false;
        }

        return preg_match('/\b(go online|online|look up|lookup|search the web|search online|internet|web|source|sources|cite|citation|verify|latest|recent reviews)\b/u', $lowerUser) === 1;
    }

    private function looksLikeWeatherQuestion(string $lowerUser): bool
    {
        return preg_match('/\b(weather|forecast|temperature|temp|rain|snow|sleet|hail|storm|wind|windy|umbrella|coat|jacket|degrees|outside|humidity)\b/u', $lowerUser) === 1;
    }

    private function looksLikeSubstantivePublicNoteCreation(string $lowerUser): bool
    {
        if (preg_match('/\b(note that|write down that|jot down that|remember that|called|titled)\b/u', $lowerUser) === 1) {
            return false;
        }

        return preg_match('/\b(note|notes)\b/u', $lowerUser) === 1
            && preg_match('/\b(save|create|make|add|write)\b/u', $lowerUser) === 1
            && preg_match('/\b(guide|instructions|steps|how to|how-to|recipe|research|compare|comparison|best|sources?)\b/u', $lowerUser) === 1
            && preg_match('/\b(no lookup|don[’\']?t look up|without looking|from your own knowledge|off the top of your head|just make one up)\b/u', $lowerUser) !== 1;
    }

    private function externalLookupHasSources(Collection $toolCalls): bool
    {
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof BeanToolCall || $toolCall->action !== 'external.lookup') {
                continue;
            }
            if ($toolCall->status !== 'completed') {
                continue;
            }
            $result = is_array($toolCall->result) ? $toolCall->result : [];
            $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
            if (collect($sources)->contains(fn ($source): bool => is_array($source) && trim((string) ($source['url'] ?? $source['snippet'] ?? $source['title'] ?? '')) !== '')) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCorrection(string $lowerUser): bool
    {
        return preg_match('/\b(that[’\']?s not what i said|not what i said|i said|i meant)\b/u', $lowerUser) === 1;
    }

    private function looksLikeReferenceFollowup(string $lowerUser): bool
    {
        if (preg_match('/\b(that[’\']?s|that is)?\s*doesn[’\']?t make sense\b/u', $lowerUser) === 1) {
            return false;
        }

        return preg_match('/\b(that task|that note|that event|that reminder|that workspace|the previous (task|note|event|reminder)|complete that|delete that|edit that|update that|move that|reschedule that|add .* to that (note|task|event|reminder))\b/u', $lowerUser) === 1;
    }

    private function asksDate(string $lowerUser): bool
    {
        return preg_match('/\b(date|today[’\']?s date|what day)\b/u', $lowerUser) === 1;
    }

    private function asksTime(string $lowerUser): bool
    {
        return preg_match('/\b(time|current time|now)\b/u', $lowerUser) === 1;
    }

    private function answerContainsDate(string $answer): bool
    {
        return preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2},\s+\d{4}\b/i', $answer) === 1
            || preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $answer) === 1
            || preg_match('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $answer) === 1;
    }

    private function todayTaskResultHasOverdue(Collection $toolCalls): bool
    {
        $start = now()->startOfDay();
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof BeanToolCall || $toolCall->action !== 'task.list') {
                continue;
            }
            $arguments = is_array($toolCall->arguments) ? $toolCall->arguments : [];
            $result = is_array($toolCall->result) ? $toolCall->result : [];
            $label = strtolower(trim((string) ($arguments['time_label'] ?? $result['time_label'] ?? '')));
            if ($label !== 'today') {
                continue;
            }
            foreach (($result['items'] ?? []) as $item) {
                if (! is_array($item) || empty($item['due_at'])) {
                    continue;
                }
                if (Carbon::parse((string) $item['due_at'])->lt($start)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function looksLikeWorkspaceQuestion(string $lowerUser): bool
    {
        return str_contains($lowerUser, 'workspace') || preg_match('/\bwhere .*\b(live|in)\b/u', $lowerUser) === 1;
    }

    private function workspaceNamesFromResults(Collection $toolCalls): array
    {
        return $toolCalls
            ->flatMap(function (BeanToolCall $toolCall): array {
                $result = is_array($toolCall->result) ? $toolCall->result : [];
                $items = is_array($result['items'] ?? null) ? $result['items'] : (is_array($result['item'] ?? null) ? [$result['item']] : []);

                return collect($items)
                    ->filter(fn ($item): bool => is_array($item))
                    ->flatMap(fn (array $item): array => is_array($item['workspace_names'] ?? null) ? $item['workspace_names'] : [])
                    ->all();
            })
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }

    private function voiceSuccessRate(Collection $traces): ?float
    {
        $voice = $traces->filter(fn (BeanQualityTrace $trace): bool => (bool) $trace->voice);
        if ($voice->isEmpty()) {
            return null;
        }
        $success = $voice->filter(fn (BeanQualityTrace $trace): bool => count($trace->quality_flags ?? []) === 0)->count();

        return round($success / $voice->count(), 4);
    }
}
