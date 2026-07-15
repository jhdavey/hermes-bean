<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class AssistantRunExecutionClaim
{
    public function __construct(
        public int $runId,
        public int $sessionId,
        public int $userMessageId,
        public int $generation,
    ) {
        if ($this->runId <= 0
            || $this->sessionId <= 0
            || $this->userMessageId <= 0
            || $this->generation <= 0) {
            throw new InvalidArgumentException('An assistant run execution claim requires positive durable identifiers.');
        }
    }
}
