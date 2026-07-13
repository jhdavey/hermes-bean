<?php

namespace App\Services;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnState;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\VoiceTurn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BrowserVoiceJobPolicy
{
    public const CONTEXTUAL_DEPENDENT_PRIORITY = -10;

    public function __construct(
        private readonly BrowserVoiceTypedWriteParser $typedWrites,
    ) {}

    /** @return array{priority: int, resource_lock_key: ?string} */
    public function forTurn(VoiceTurn $turn): array
    {
        if ($turn->lane !== VoiceTurnLane::AppWrite) {
            return ['priority' => 0, 'resource_lock_key' => null];
        }

        $isDeletion = str_ends_with($turn->handler, '.delete');
        $isCorrection = str_ends_with($turn->handler, '.reschedule')
            || str_ends_with($turn->handler, '.complete');
        $isIndependentCreate = str_ends_with($turn->handler, '.create');
        $domain = $this->domain($turn->handler);

        if ($isIndependentCreate) {
            $title = $domain === null ? null : $this->createdTitle($turn, $domain);

            return [
                'priority' => 0,
                'resource_lock_key' => $domain !== null && $title !== null
                    ? $this->referenceLockKey($domain, $title)
                    : null,
            ];
        }

        $priority = $isDeletion ? 100 : ($isCorrection ? 80 : 50);
        if ($domain === null) {
            return ['priority' => $priority, 'resource_lock_key' => $turn->handler];
        }

        // A contextual or explicitly matching mutation belongs to the exact
        // earlier create chain, not to a candidate chosen from current rows.
        // Seal both jobs to one reference lock; active creates claim first,
        // while terminal creates still provide the receipt identity used by
        // fail-closed execution.
        $priorCreate = $this->priorCreateDependency($turn, $domain);
        if ($priorCreate instanceof VoiceTurn) {
            $title = $this->createdTitle($priorCreate, $domain);
            if ($title !== null) {
                $resourceLockKey = $this->referenceLockKey($domain, $title);
                $this->rememberContextDependency($turn, $priorCreate, $domain, $resourceLockKey);

                return [
                    'priority' => $priorCreate->state->isTerminal()
                        ? $priority
                        : self::CONTEXTUAL_DEPENDENT_PRIORITY,
                    'resource_lock_key' => $resourceLockKey,
                ];
            }
        }

        $resource = $this->resolveResource($turn, $domain);
        if ($resource instanceof Model) {
            $resourceTitle = trim((string) $resource->getAttribute('title'));

            return [
                'priority' => $priority,
                'resource_lock_key' => $resourceTitle !== ''
                    ? $this->referenceLockKey($domain, $resourceTitle)
                    : "app.{$domain}.resource.".$resource->getKey(),
            ];
        }

        $reference = $this->referencedTitle($turn->transcript);
        if ($reference !== null) {
            return [
                'priority' => $priority,
                'resource_lock_key' => $this->referenceLockKey($domain, $reference),
            ];
        }

        // An unresolved contextual mutation is conservatively serialized within
        // its domain. Independent creates never use this fallback.
        return ['priority' => $priority, 'resource_lock_key' => "app.{$domain}.mutation"];
    }

    public function isContextualMutationReference(string $handler, string $transcript): bool
    {
        if (preg_match('/^app\.(?:calendar|reminder|task|note)\.(?:delete|reschedule|complete)$/', $handler) !== 1
            || preg_match('/\b(?:titled|called|named)\b/iu', $transcript) === 1
            || (str_ends_with($handler, '.delete') && $this->typedWrites->hasClockTime($transcript))) {
            return false;
        }

        return preg_match(
            '/\b(?:delete|remove|move|change|reschedule|complete|mark)\s+(?:(?:that|this)(?:\s+(?:reminder|task|note|(?:calendar\s+)?event|meeting|appointment))?|it|the\s+one)\b/iu',
            $transcript,
        ) === 1;
    }

    private function priorCreateDependency(VoiceTurn $turn, string $domain): ?VoiceTurn
    {
        if ($turn->id === null) {
            return null;
        }

        $contextual = $this->isContextualMutationReference($turn->handler, $turn->transcript);
        $explicitTitle = $this->referencedTitle($turn->transcript);
        if (! $contextual && $explicitTitle === null) {
            return null;
        }

        $priorCreates = VoiceTurn::query()
            ->where('user_id', $turn->user_id)
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('id', '<', $turn->id)
            ->where('handler', "app.{$domain}.create")
            ->latest('id')
            ->limit(50)
            ->get();
        if ($contextual) {
            if ($this->allowsPriorConversationContext($turn)) {
                $epoch = (int) data_get($turn->metadata, 'conversation_context.epoch', -1);
                $generation = (int) data_get($turn->metadata, 'controller_generation', -1);
                $prior = $priorCreates->first(fn (VoiceTurn $candidate): bool => (int) data_get($candidate->metadata, 'conversation_context.epoch', -2) === $epoch
                    && (int) data_get($candidate->metadata, 'controller_generation', -2) === $generation
                );
            } else {
                $active = $priorCreates->filter(fn (VoiceTurn $candidate): bool => in_array(
                    $candidate->state,
                    [VoiceTurnState::Accepted, VoiceTurnState::Running],
                    true,
                ))->values();
                $prior = $active->count() === 1 ? $active->first() : null;
            }

            return $prior instanceof VoiceTurn ? $prior : null;
        }

        $normalizedExplicitTitle = $this->normalizeReference($explicitTitle);
        $prior = $priorCreates->first(function (VoiceTurn $candidate) use ($domain, $normalizedExplicitTitle): bool {
            $title = $this->createdTitle($candidate, $domain);

            return $title !== null && $this->normalizeReference($title) === $normalizedExplicitTitle;
        });

        return $prior instanceof VoiceTurn ? $prior : null;
    }

    private function rememberContextDependency(
        VoiceTurn $turn,
        VoiceTurn $priorCreate,
        string $domain,
        string $resourceLockKey,
    ): void {
        $fresh = VoiceTurn::query()->find($turn->id);
        if (! $fresh instanceof VoiceTurn) {
            return;
        }

        $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
        $dependencies = is_array(data_get($metadata, 'contextual_create_dependencies'))
            ? data_get($metadata, 'contextual_create_dependencies')
            : [];
        $metadata['contextual_create_dependencies'] = [
            ...$dependencies,
            $resourceLockKey => [
                'voice_turn_id' => $priorCreate->id,
                'turn_id' => $priorCreate->turn_id,
                'domain' => $domain,
                'resource_lock_key' => $resourceLockKey,
            ],
        ];
        $fresh->update(['metadata' => $metadata]);
        $turn->setAttribute('metadata', $metadata);
    }

    private function createdTitle(VoiceTurn $turn, string $domain): ?string
    {
        if (in_array($domain, ['reminder', 'calendar'], true)) {
            $typed = $this->typedWrites->parseCreate(
                $turn->transcript,
                $turn->handler,
                is_string(data_get($turn->metadata, 'timezone')) ? data_get($turn->metadata, 'timezone') : null,
                $turn->accepted_at,
                is_string(data_get($turn->metadata, 'contextual_reference.title'))
                    ? data_get($turn->metadata, 'contextual_reference.title')
                    : null,
            );
            if ($typed?->title !== null) {
                return $typed->title;
            }
        }

        $title = $this->referencedTitle($turn->transcript);
        if ($title !== null) {
            return $title;
        }

        $noun = match ($domain) {
            'calendar' => '(?:(?:calendar )?(?:event|meeting|appointment))',
            'reminder' => 'reminder',
            'task' => 'task',
            'note' => 'note',
            default => null,
        };
        if ($noun === null) {
            return null;
        }

        $temporalBoundary = '(?=\s+(?:(?:for|on|at|by)\s+)?(?:today|tomorrow|tonight|noon|midnight|(?:next\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|\d{1,2}(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?))\b|[.!?]*$)';
        if (preg_match('/\b(?:titled|called|named)\s+[“"]?(.+?)[”"]?[.!?]*$/iu', $turn->transcript, $match) === 1) {
            $candidate = trim((string) $match[1], " \t\n\r\0\x0B\"“”.,!?;");
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 180);
            }
        }
        $patterns = [
            '/\b(?:create|add|make|set|schedule|save|book)\s+(?:a\s+|an\s+|the\s+)?'.$noun.'\b\s+(?:to\s+|about\s+)?(?!for\b|on\b|at\b|by\b|today\b|tomorrow\b|tonight\b)[“"]?(.+?)[”"]?'.$temporalBoundary.'/iu',
        ];
        if ($domain === 'reminder') {
            $patterns[] = '/\bremind me to\s+[“"]?(.+?)[”"]?'.$temporalBoundary.'/iu';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $turn->transcript, $match) !== 1) {
                continue;
            }
            $candidate = trim((string) $match[1], " \t\n\r\0\x0B\"“”.,!?;");
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 180);
            }
        }

        return null;
    }

    private function referenceLockKey(string $domain, string $title): string
    {
        $normalized = $this->normalizeReference($title);

        return "app.{$domain}.reference.".substr(hash('sha256', $normalized), 0, 24);
    }

    private function normalizeReference(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized, " \t\n\r\0\x0B\"'“”‘’.,!?;");
    }

    private function resolveResource(VoiceTurn $turn, string $domain): ?Model
    {
        $query = $this->resourceQuery($turn, $domain);
        if (! $query instanceof Builder) {
            return null;
        }

        if ($this->isContextualMutationReference($turn->handler, $turn->transcript)
            && ! $this->allowsPriorConversationContext($turn)) {
            return null;
        }

        $candidates = $query->latest('id')->limit(50)->get();
        $title = $this->referencedTitle($turn->transcript);
        if ($title !== null) {
            $needle = mb_strtolower($title);
            $candidates = $candidates->filter(fn (Model $candidate): bool => str_contains(
                mb_strtolower((string) $candidate->getAttribute('title')),
                $needle,
            ));
        }

        if ($candidates->count() > 1 && preg_match('/\b(?:that|it|the one|this)\b/iu', $turn->transcript) === 1) {
            $priorAnswer = VoiceTurn::query()
                ->where('user_id', $turn->user_id)
                ->where('conversation_session_id', $turn->conversation_session_id)
                ->where('turn_id', (string) data_get($turn->metadata, 'prior_turn_id', ''))
                ->first()?->finalAssistantMessage()
                ->value('content');
            if (is_string($priorAnswer)) {
                $mentioned = $candidates->filter(fn (Model $candidate): bool => str_contains(
                    mb_strtolower($priorAnswer),
                    mb_strtolower((string) $candidate->getAttribute('title')),
                ));
                if ($mentioned->count() === 1) {
                    return $mentioned->first();
                }
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function allowsPriorConversationContext(VoiceTurn $turn): bool
    {
        return data_get($turn->metadata, 'prior_context_authorized') === true;
    }

    private function resourceQuery(VoiceTurn $turn, string $domain): ?Builder
    {
        $query = match ($domain) {
            'reminder' => Reminder::query(),
            'task' => Task::query(),
            'note' => Note::query(),
            'calendar' => CalendarEvent::query(),
            default => null,
        };
        if (! $query instanceof Builder) {
            return null;
        }

        return $query
            ->where('user_id', $turn->user_id)
            ->where('workspace_id', $turn->workspace_id);
    }

    private function domain(string $handler): ?string
    {
        if (preg_match('/^app\.(calendar|reminder|task|note)\.(?:create|delete|reschedule|complete)$/', $handler, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function referencedTitle(string $text): ?string
    {
        if (preg_match(
            '/\b(?:reminder|task|note|(?:calendar )?event|meeting|appointment)\b\s+(?:titled|called|named)\s+[“"]?(.+?)(?=[”"]?\s+(?:to|for|on|at|today|tomorrow)\b|[”"]?[.!]*$)/iu',
            $text,
            $match,
        ) !== 1) {
            return null;
        }

        $title = trim((string) $match[1], " \t\n\r\0\x0B\"“”");

        return $title !== '' ? $title : null;
    }
}
