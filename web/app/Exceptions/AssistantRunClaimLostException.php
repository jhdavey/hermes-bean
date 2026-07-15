<?php

namespace App\Exceptions;

use RuntimeException;

class AssistantRunClaimLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The assistant run execution claim is no longer current.');
    }
}
