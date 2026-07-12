<?php

namespace App\Exceptions;

use RuntimeException;

class BrowserVoiceHandlerException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $internalDetail,
        public readonly string $userFacingText,
        public readonly bool $retriable = false,
    ) {
        parent::__construct($internalDetail);
    }
}
