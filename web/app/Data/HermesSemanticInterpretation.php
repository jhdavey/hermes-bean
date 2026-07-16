<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class HermesSemanticInterpretation
{
    public const OUTCOME_RESPOND = 'respond';

    public const OUTCOME_CLARIFY = 'clarify';

    public const OUTCOME_EXECUTE = 'execute';

    /**
     * @param  list<HermesSemanticOperation>  $operations
     */
    public function __construct(
        public string $outcome,
        public ?string $responseText,
        public ?string $clarificationQuestion,
        public ?string $acknowledgementText,
        public bool $closeAfterResponse,
        public bool $responseExpected,
        public array $operations,
    ) {
        if (! in_array($this->outcome, [self::OUTCOME_RESPOND, self::OUTCOME_CLARIFY, self::OUTCOME_EXECUTE], true)) {
            throw new InvalidArgumentException('The semantic interpretation outcome is invalid.');
        }

        foreach ($this->operations as $operation) {
            if (! $operation instanceof HermesSemanticOperation) {
                throw new InvalidArgumentException('Semantic interpretation operations must be typed operations.');
            }
        }

        if ($this->closeAfterResponse && $this->responseExpected) {
            throw new InvalidArgumentException('A semantic response cannot both close the conversation and expect an answer.');
        }

        $this->assertOutcomeShape();
        $this->assertOperationGraph();
    }

    /** @param array<string, mixed> $payload */
    public static function fromProviderPayload(array $payload): self
    {
        $outcome = is_string($payload['outcome'] ?? null) ? $payload['outcome'] : '';
        $outcomeText = self::nullableString($payload, 'outcome_text');
        $operationPayloads = $payload['operations'] ?? null;
        if (! is_array($operationPayloads) || ! array_is_list($operationPayloads)) {
            throw new InvalidArgumentException('Semantic interpretation operations must be a list.');
        }

        $operations = [];
        foreach ($operationPayloads as $operationPayload) {
            if (! is_array($operationPayload)) {
                throw new InvalidArgumentException('Each semantic interpretation operation must be an object.');
            }

            $operations[] = HermesSemanticOperation::fromProviderPayload($operationPayload);
        }

        return new self(
            outcome: $outcome,
            responseText: $outcome === self::OUTCOME_RESPOND ? $outcomeText : null,
            clarificationQuestion: $outcome === self::OUTCOME_CLARIFY ? $outcomeText : null,
            acknowledgementText: $outcome === self::OUTCOME_EXECUTE ? $outcomeText : null,
            closeAfterResponse: self::requiredBoolean($payload, 'close_after_response'),
            responseExpected: self::requiredBoolean($payload, 'response_expected'),
            operations: $operations,
        );
    }

    /**
     * @return array{
     *     outcome:string,
     *     response_text:?string,
     *     clarification_question:?string,
     *     acknowledgement_text:?string,
     *     close_after_response:bool,
     *     response_expected:bool,
     *     operations:list<array{id:string,tool:string,arguments:array<string,mixed>,dependencies:list<string>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'response_text' => $this->responseText,
            'clarification_question' => $this->clarificationQuestion,
            'acknowledgement_text' => $this->acknowledgementText,
            'close_after_response' => $this->closeAfterResponse,
            'response_expected' => $this->responseExpected,
            'operations' => array_map(
                static fn (HermesSemanticOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }

    private function assertOutcomeShape(): void
    {
        $hasResponse = trim((string) $this->responseText) !== '';
        $hasClarification = trim((string) $this->clarificationQuestion) !== '';
        $hasAcknowledgement = trim((string) $this->acknowledgementText) !== '';

        if ($this->outcome === self::OUTCOME_RESPOND
            && (! $hasResponse || $hasClarification || $hasAcknowledgement || $this->operations !== [])) {
            throw new InvalidArgumentException('A respond outcome must contain only a final response.');
        }

        if ($this->outcome === self::OUTCOME_CLARIFY
            && (! $hasClarification
                || $hasResponse
                || $hasAcknowledgement
                || $this->closeAfterResponse
                || ! $this->responseExpected
                || $this->operations !== [])) {
            throw new InvalidArgumentException('A clarify outcome must contain only one clarification question.');
        }

        if ($this->outcome === self::OUTCOME_EXECUTE
            && ($this->operations === []
                || $hasResponse
                || $hasClarification
                || $this->closeAfterResponse
                || $this->responseExpected)) {
            throw new InvalidArgumentException('An execute outcome must contain operations and may not claim a final result.');
        }
    }

    private function assertOperationGraph(): void
    {
        $seen = [];
        foreach ($this->operations as $operation) {
            if (isset($seen[$operation->id])) {
                throw new InvalidArgumentException('Semantic operation ids must be unique.');
            }

            foreach ($operation->dependencies as $dependency) {
                if (! isset($seen[$dependency])) {
                    throw new InvalidArgumentException('A semantic operation may depend only on an earlier operation.');
                }
            }

            $seen[$operation->id] = true;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        if (! is_string($payload[$key])) {
            throw new InvalidArgumentException("Semantic interpretation {$key} must be a string or null.");
        }

        return $payload[$key];
    }

    /** @param array<string, mixed> $payload */
    private static function requiredBoolean(array $payload, string $key): bool
    {
        if (! array_key_exists($key, $payload) || ! is_bool($payload[$key])) {
            throw new InvalidArgumentException("Semantic interpretation {$key} must be a boolean.");
        }

        return $payload[$key];
    }
}
