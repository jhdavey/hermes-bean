<?php

namespace App\Enums;

enum VoiceTurnSideEffectStatus: string
{
    case None = 'none';
    case Committed = 'committed';
    case NotCommitted = 'not_committed';
    case Uncertain = 'uncertain';
}
