<?php

namespace App\Services;

use App\Data\VoiceTurnRoute;
use App\Enums\VoiceTurnLane;
use App\Models\VoiceTurn;
use Illuminate\Support\Str;

class BrowserVoiceComplexPlanService
{
    public function __construct(
        private readonly BrowserVoiceAdmissionRouter $router,
        private readonly BrowserVoiceJobPolicy $jobPolicy,
        private readonly BrowserVoiceSubtaskSplitter $subtasks,
    ) {}

    /**
     * Seal deterministic, independently executable subrequests before any job
     * is dispatched. Requests that require shared reasoning remain one agent
     * job so a guessed decomposition can never duplicate a side effect.
     *
     * @return array<int, array{
     *   key: string,
     *   label: string,
     *   lane: VoiceTurnLane,
     *   handler: string,
     *   input: string,
     *   priority: int,
     *   resource_lock_key: ?string,
     *   hard_deadline_seconds: int,
     *   metadata: array<string, mixed>
     * }>
     */
    public function plan(VoiceTurn $turn): array
    {
        if ($turn->lane !== VoiceTurnLane::ComplexAgent || $turn->handler !== 'agent.complex') {
            return [$this->primary($turn)];
        }

        $segments = $this->subtasks->split($turn->transcript);
        if (count($segments) < 2) {
            return [$this->primary($turn)];
        }

        $planned = [];
        $plannedCreatesByDomain = [];
        $plannedCreatesByLock = [];
        $previousRoute = null;
        foreach ($segments as $index => $segment) {
            $segment = trim($segment);
            $route = $this->router->route($segment, is_array($turn->metadata) ? $turn->metadata : []);
            if ($route->lane === VoiceTurnLane::AppRead
                && $previousRoute instanceof VoiceTurnRoute
                && $previousRoute->lane === VoiceTurnLane::AppWrite
                && str_ends_with($previousRoute->handler, '.create')
                && $this->handlerDomain($route->handler) === $this->handlerDomain($previousRoute->handler)
                && preg_match('/^(?:another|one\s+more|a\s+second|the\s+second)\b/iu', $segment) === 1) {
                $route = new VoiceTurnRoute(
                    VoiceTurnLane::AppWrite,
                    $previousRoute->handler,
                    false,
                    null,
                    5,
                    2,
                );
                $segment = 'Create '.$segment;
            }
            if (in_array($route->lane, [VoiceTurnLane::Instant, VoiceTurnLane::ComplexAgent], true)) {
                return [$this->primary($turn)];
            }
            $input = $this->withSharedTemporalContext($segment, $turn->transcript, $route->handler);

            $operation = clone $turn;
            $operation->setAttribute('lane', $route->lane->value);
            $operation->setAttribute('handler', $route->handler);
            $operation->setAttribute('transcript', $input);
            $key = 'subtask-'.($index + 1).'-'.str_replace('.', '-', $route->handler);
            $domain = $this->handlerDomain($route->handler);
            $isMutation = preg_match('/^app\.(?:calendar|reminder|task|note)\.(?:delete|reschedule|complete)$/', $route->handler) === 1;
            $isContextualMutation = $this->jobPolicy->isContextualMutationReference($route->handler, $input);
            $createDependency = $domain !== null && $isContextualMutation
                ? ($plannedCreatesByDomain[$domain] ?? null)
                : null;
            $policy = is_array($createDependency)
                ? [
                    'priority' => BrowserVoiceJobPolicy::CONTEXTUAL_DEPENDENT_PRIORITY,
                    'resource_lock_key' => $createDependency['resource_lock_key'],
                ]
                : $this->jobPolicy->forTurn($operation);
            if (! is_array($createDependency)
                && $domain !== null
                && $isMutation
                && is_string($policy['resource_lock_key'])) {
                $createDependency = $plannedCreatesByLock[$policy['resource_lock_key']] ?? null;
                if (is_array($createDependency)) {
                    $policy['priority'] = BrowserVoiceJobPolicy::CONTEXTUAL_DEPENDENT_PRIORITY;
                }
            }
            $metadata = [
                'required' => true,
                'complex_plan_index' => $index,
                'complex_plan_size' => count($segments),
                'planned_handler' => $route->handler,
            ];
            if (is_array($createDependency)) {
                $metadata['contextual_create_dependency'] = [
                    'scope' => 'same_turn',
                    'predecessor_job_key' => $createDependency['key'],
                    'predecessor_idempotency_key' => $createDependency['idempotency_key'],
                    'predecessor_handler' => $createDependency['handler'],
                    'domain' => $domain,
                    'intended_resource_type' => $domain === 'calendar' ? 'calendar_event' : $domain,
                    'resource_lock_key' => $createDependency['resource_lock_key'],
                ];
            }
            $planned[] = [
                'key' => $key,
                'label' => $this->label($route->handler, $input),
                'lane' => $route->lane,
                'handler' => $route->handler,
                'input' => $input,
                'priority' => $policy['priority'],
                'resource_lock_key' => $policy['resource_lock_key'],
                'hard_deadline_seconds' => $route->hardDeadlineSeconds,
                'metadata' => $metadata,
            ];
            if ($domain !== null
                && $route->lane === VoiceTurnLane::AppWrite
                && str_ends_with($route->handler, '.create')
                && is_string($policy['resource_lock_key'])
                && $policy['resource_lock_key'] !== '') {
                $plannedCreatesByDomain[$domain] = [
                    'key' => $key,
                    'idempotency_key' => $turn->turn_id.':'.Str::slug($key, '-'),
                    'handler' => $route->handler,
                    'resource_lock_key' => $policy['resource_lock_key'],
                ];
                $plannedCreatesByLock[$policy['resource_lock_key']] = $plannedCreatesByDomain[$domain];
            }
            $previousRoute = $route;
        }

        return count($planned) > 1 ? $planned : [$this->primary($turn)];
    }

    /** @return array<string, mixed> */
    private function primary(VoiceTurn $turn): array
    {
        $label = $turn->handler === 'agent.generate_note'
            ? $this->generatedNoteLabel($turn->transcript)
            : 'Work on request';

        return [
            'key' => 'primary',
            'label' => $label,
            'lane' => $turn->lane,
            'handler' => $turn->handler,
            'input' => $turn->transcript,
            'priority' => 0,
            'resource_lock_key' => null,
            'hard_deadline_seconds' => 120,
            'metadata' => ['required' => true, 'complex_plan_size' => 1],
        ];
    }

    private function withSharedTemporalContext(string $segment, string $completeRequest, string $handler): string
    {
        if (! preg_match('/^(?:app\.(?:calendar|reminder|task)\.read|external\.weather)$/', $handler)) {
            return $segment;
        }

        $context = [];
        $datePattern = '/\b(?:today|tomorrow|tonight|this\s+(?:morning|afternoon|evening)|(?:this|next)\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/iu';
        if (preg_match($datePattern, $segment) !== 1
            && preg_match($datePattern, $completeRequest, $date) === 1) {
            $context[] = $date[0];
        }
        $timePattern = '/\b(?:at|around|by)\s+(?:noon|midnight|\d{1,2}(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?))\b/iu';
        if (preg_match($timePattern, $segment) !== 1
            && preg_match($timePattern, $completeRequest, $time) === 1) {
            $context[] = $time[0];
        }
        if ($context === []) {
            return $segment;
        }

        return rtrim($segment, " \t\n\r\0\x0B.?!").' '.implode(' ', $context).'.';
    }

    private function label(string $handler, string $input): string
    {
        $base = match (true) {
            $handler === 'app.calendar.read' => 'Check calendar',
            $handler === 'app.reminder.read' => 'Check reminders',
            $handler === 'app.task.read' => 'Check tasks',
            $handler === 'app.note.read' => 'Check notes',
            str_starts_with($handler, 'app.calendar.') => 'Update calendar',
            str_starts_with($handler, 'app.reminder.') => 'Update reminders',
            str_starts_with($handler, 'app.task.') => 'Update tasks',
            str_starts_with($handler, 'app.note.') => 'Update notes',
            $handler === 'external.weather' => 'Check weather',
            default => 'Work on request',
        };
        if (! preg_match('/^app\.(?:calendar|reminder|task|note)\.(?:create|delete|reschedule|complete)$/', $handler)) {
            return $base;
        }

        $target = null;
        if (preg_match('/\b(?:titled|called|named|labeled|labelled)\s+[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow)\b|[”"]?[.!]*$)/iu', $input, $match) === 1) {
            $target = trim((string) $match[1], " \t\n\r\0\x0B\"“”");
        } elseif (preg_match('/\bremind me to\s+[“"]?(.+?)(?=[”"]?\s+(?:for|on|at|today|tomorrow)\b|[”"]?[.!]*$)/iu', $input, $match) === 1) {
            $target = trim((string) $match[1], " \t\n\r\0\x0B\"“”");
        }

        return $target === null || $target === ''
            ? $base
            : $base.': '.mb_substr($target, 0, 80);
    }

    private function handlerDomain(string $handler): ?string
    {
        return preg_match('/^app\.(calendar|reminder|task|note)\./', $handler, $match) === 1
            ? $match[1]
            : null;
    }

    private function generatedNoteLabel(string $input): string
    {
        if (preg_match('/\b(?:meal|dinner|lunch)\s+plan\b/iu', $input) === 1) {
            return 'Create meal plan note';
        }
        if (preg_match('/\b(?:travel|workout|study)\s+plan\b/iu', $input, $match) === 1) {
            return 'Create '.mb_strtolower((string) $match[0]).' note';
        }

        return 'Create generated note';
    }
}
