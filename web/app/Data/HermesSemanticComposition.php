<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class HermesSemanticComposition
{
    public function __construct(
        public string $responseText,
        public bool $closeAfterResponse,
        public bool $responseExpected,
    ) {
        if (trim($this->responseText) === '') {
            throw new InvalidArgumentException('Hermes response composition may not be empty.');
        }

        if ($this->closeAfterResponse && $this->responseExpected) {
            throw new InvalidArgumentException('A composed response cannot both close the conversation and expect an answer.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromProviderPayload(array $payload): self
    {
        if (! is_bool($payload['close_after_response'] ?? null)
            || ! is_bool($payload['response_expected'] ?? null)) {
            throw new InvalidArgumentException('Hermes response composition directives must be boolean.');
        }

        return new self(
            responseText: is_string($payload['response_text'] ?? null) ? $payload['response_text'] : '',
            closeAfterResponse: $payload['close_after_response'],
            responseExpected: $payload['response_expected'],
        );
    }

    /** @return array{response_text:string,close_after_response:bool,response_expected:bool} */
    public function toArray(): array
    {
        return [
            'response_text' => $this->responseText,
            'close_after_response' => $this->closeAfterResponse,
            'response_expected' => $this->responseExpected,
        ];
    }
}
