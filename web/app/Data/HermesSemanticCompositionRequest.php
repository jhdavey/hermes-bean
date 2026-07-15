<?php

namespace App\Data;

use App\Models\User;
use InvalidArgumentException;
use JsonException;

final readonly class HermesSemanticCompositionRequest
{
    /**
     * @param  list<HermesSemanticOperationResult>  $results
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public User $user,
        public ?int $workspaceId,
        public string $stableTurnId,
        public string $transcript,
        public string $currentTime,
        public ?string $timezone,
        public HermesSemanticInterpretation $interpretation,
        public array $results,
        public array $context = [],
        public string $locale = 'en-US',
        public ?int $conversationSessionId = null,
        public ?int $conversationMessageId = null,
    ) {
        if (! $this->user->exists || ! $this->user->getKey()) {
            throw new InvalidArgumentException('Semantic composition requires a persisted user for usage enforcement.');
        }

        foreach ([
            'stable turn id' => $this->stableTurnId,
            'transcript' => $this->transcript,
            'current time' => $this->currentTime,
            'locale' => $this->locale,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Semantic composition {$label} may not be empty.");
            }
        }

        if ($this->timezone !== null) {
            try {
                new \DateTimeZone($this->timezone);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException(
                    'Semantic composition timezone must be a valid IANA timezone, explicit UTC offset, or null.',
                    previous: $exception,
                );
            }
        }

        if ($this->interpretation->outcome !== HermesSemanticInterpretation::OUTCOME_EXECUTE) {
            throw new InvalidArgumentException('Post-execution composition requires an execute interpretation.');
        }

        $this->assertCompleteTerminalResults();

        try {
            json_encode($this->context, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Semantic composition context must be JSON encodable.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'transcript' => $this->transcript,
            'current_time' => $this->currentTime,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'interpretation' => $this->interpretation->toArray(),
            'results' => array_map(
                static fn (HermesSemanticOperationResult $result): array => $result->toArray(),
                $this->results,
            ),
            'context' => $this->context,
        ];
    }

    private function assertCompleteTerminalResults(): void
    {
        $operationsById = [];
        foreach ($this->interpretation->operations as $operation) {
            $operationsById[$operation->id] = $operation;
        }

        $seen = [];
        foreach ($this->results as $result) {
            if (! $result instanceof HermesSemanticOperationResult) {
                throw new InvalidArgumentException('Semantic composition results must be typed terminal results.');
            }

            $operation = $operationsById[$result->operationId] ?? null;
            if (! $operation instanceof HermesSemanticOperation || $operation->tool !== $result->tool) {
                throw new InvalidArgumentException('A semantic composition result does not match its planned operation.');
            }

            if (isset($seen[$result->operationId])) {
                throw new InvalidArgumentException('A semantic composition may not repeat an operation result.');
            }

            $seen[$result->operationId] = true;
        }

        if (count($seen) !== count($operationsById)) {
            throw new InvalidArgumentException('Semantic composition requires one terminal result for every planned operation.');
        }
    }
}
