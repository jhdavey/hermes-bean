<?php

namespace App\Exceptions;

final class HermesSemanticUsageLimitException extends HermesSemanticProviderException
{
    public readonly string $userFacingText;

    /** @param array<string, mixed> $preflight */
    public function __construct(
        string $reason,
        public readonly array $preflight,
    ) {
        $this->userFacingText = $reason;

        parent::__construct(
            category: 'usage_limit',
            internalDetail: $reason,
            retriable: false,
        );
    }
}
