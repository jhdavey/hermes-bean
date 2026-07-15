<?php

namespace App\Exceptions;

use RuntimeException;

class HermesSemanticProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $internalDetail,
        public readonly bool $retriable = false,
    ) {
        parent::__construct($internalDetail);
    }
}
