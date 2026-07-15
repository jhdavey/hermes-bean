<?php

namespace App\Contracts;

use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeEvent;
use App\Models\VoiceRealtimeSession;

interface RealtimeVoiceProviderEventHandler
{
    public function handle(VoiceRealtimeEvent $event): void;

    /** Recover pending durable deliveries when a replacement sideband becomes ready. */
    public function handleSessionReady(VoiceRealtimeSession $session): void;

    /** Terminalize the event's bound turn after the durable retry budget is exhausted. */
    public function handleEventFailure(VoiceRealtimeEvent $event): void;

    /** Reconcile an outbound command whose provider-delivery outcome is unsafe to retry. */
    public function handleCommandFailure(VoiceRealtimeCommand $command): void;

    /** Terminalize affected active turns when the sideband reconnect budget is exhausted. */
    public function handleSessionFailure(VoiceRealtimeSession $session): void;
}
