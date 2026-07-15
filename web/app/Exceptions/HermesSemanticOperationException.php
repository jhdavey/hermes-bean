<?php

namespace App\Exceptions;

use RuntimeException;

class HermesSemanticOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $internalDetail,
    ) {
        parent::__construct($internalDetail);
    }
}
