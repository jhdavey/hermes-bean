<?php

namespace App\Enums;

enum VoiceTurnLane: string
{
    case Instant = 'instant';
    case AppRead = 'app_read';
    case AppWrite = 'app_write';
    case External = 'external';
    case ComplexAgent = 'complex_agent';
}
