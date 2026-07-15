<?php

namespace App\Enums;

enum VoiceTurnLane: string
{
    case Semantic = 'semantic';
    case AppRead = 'app_read';
    case AppWrite = 'app_write';
    case External = 'external';
}
