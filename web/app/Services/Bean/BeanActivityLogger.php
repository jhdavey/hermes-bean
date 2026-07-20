<?php

namespace App\Services\Bean;

use App\Models\BeanActivityEvent;
use App\Models\BeanRun;
use App\Models\BeanSession;

class BeanActivityLogger
{
    public function log(BeanSession $session, ?BeanRun $run, string $type, string $label, array $payload = []): BeanActivityEvent
    {
        return BeanActivityEvent::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run?->id,
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'type' => $type,
            'label' => mb_substr($label, 0, 240),
            'payload' => $payload ?: null,
        ]);
    }
}
