<?php

namespace App\Services;

use App\Contracts\RealtimeVoiceProviderEventHandler;
use App\Contracts\RealtimeVoiceSidebandConnection;
use App\Contracts\RealtimeVoiceSidebandTransport;
use App\Enums\VoiceRealtimeCommandStatus;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Throwable;

class RealtimeVoiceSidebandDaemon
{
    /** Provider events that can change Laravel-owned voice state. */
    private const APPLICATION_EVENT_TYPES = [
        'input_audio_buffer.committed',
        'response.created',
        'response.output_item.done',
        'response.done',
        'response.output_audio_transcript.done',
        'response.audio_transcript.done',
        'error',
    ];

    /** @var array<int, array{session: VoiceRealtimeSession, connection: RealtimeVoiceSidebandConnection}> */
    private array $connections = [];

    /** @var array<int, VoiceRealtimeSession> */
    private array $connecting = [];

    /** @var array<int, true> */
    private array $readyRecoveryPending = [];

    /** @var array<int, TimerInterface> */
    private array $timers = [];

    private bool $stopping = false;

    private string $restartSignal = 'initial';

    private readonly string $leaseOwner;

    public function __construct(
        private readonly RealtimeVoiceSessionService $sessions,
        private readonly RealtimeVoiceEventService $events,
        private readonly RealtimeVoiceCommandService $commands,
        private readonly RealtimeVoiceSidebandTransport $transport,
        private readonly RealtimeVoiceProviderEventHandler $eventHandler,
        private readonly RealtimeVoiceSidebandRestartSignal $restart,
        ?string $leaseOwner = null,
    ) {
        $this->leaseOwner = $leaseOwner ?: sprintf(
            '%s:%d:%s',
            gethostname() ?: 'bean',
            getmypid() ?: 0,
            Str::uuid(),
        );
    }

    public function run(bool $once = false): void
    {
        $this->stopping = false;
        $this->restartSignal = $this->restart->current();
        $loop = Loop::get();

        $loop->futureTick(fn () => $this->tick());
        $this->timers[] = $loop->addPeriodicTimer(
            max(0.02, (int) config('services.voice_realtime.scan_interval_ms', 100) / 1000),
            fn () => $this->tick(),
        );
        $this->timers[] = $loop->addPeriodicTimer(
            max(0.01, (int) config('services.voice_realtime.command_interval_ms', 25) / 1000),
            fn () => $this->drainAll(),
        );
        $this->timers[] = $loop->addPeriodicTimer(
            max(1, (int) config('services.voice_realtime.heartbeat_seconds', 5)),
            fn () => $this->heartbeat(),
        );
        $this->timers[] = $loop->addPeriodicTimer(1, function (): void {
            if (! hash_equals($this->restartSignal, $this->restart->current())) {
                $this->stop();
            }
        });

        if ($once) {
            $this->timers[] = $loop->addTimer(
                max(0.05, (int) config('services.voice_realtime.once_grace_ms', 500) / 1000),
                fn () => $this->stop(),
            );
        }

        $loop->run();
    }

    public function tick(): void
    {
        if ($this->stopping) {
            return;
        }

        foreach ($this->sessions->failuresAwaitingReconciliation() as $failedSession) {
            $this->reconcileSessionFailure($failedSession);
        }

        foreach ($this->sessions->claimableSessions() as $candidate) {
            if (isset($this->connections[$candidate->id]) || isset($this->connecting[$candidate->id])) {
                continue;
            }

            $leased = $this->sessions->acquireLease($candidate, $this->leaseOwner);
            if ($leased !== null) {
                $this->beginConnection($leased);
            } elseif ($candidate->fresh()?->status?->isTerminal()) {
                $fresh = $candidate->fresh();
                if ($fresh instanceof VoiceRealtimeSession) {
                    $this->reconcileSessionFailure($fresh);
                }
            }
        }
    }

    public function drainAll(): void
    {
        if ($this->stopping) {
            return;
        }

        foreach (array_keys($this->connections) as $sessionId) {
            $state = $this->connections[$sessionId] ?? null;
            if ($state !== null) {
                foreach ($this->commands->failuresAwaitingReconciliation($state['session']) as $command) {
                    $this->reconcileCommandFailure($command);
                }
            }
            $this->recoverEvents($sessionId);
            $this->drain($sessionId);
        }
    }

    public function heartbeat(): void
    {
        if ($this->stopping) {
            return;
        }

        foreach ($this->connections as $sessionId => $state) {
            if (isset($this->readyRecoveryPending[$sessionId])) {
                $this->recoverReadySession($state['session']);
            }
            if (! $this->sessions->renewLease($sessionId, $this->leaseOwner)) {
                unset($this->connections[$sessionId], $this->readyRecoveryPending[$sessionId]);
                $state['connection']->close(1008, 'Sideband lease lost');
            }
        }
    }

    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;

        foreach ($this->timers as $timer) {
            Loop::cancelTimer($timer);
        }
        $this->timers = [];

        $active = $this->connections;
        $connecting = $this->connecting;
        $this->connections = [];
        $this->connecting = [];
        $this->readyRecoveryPending = [];

        foreach ($active as $state) {
            $this->releaseForRestart($state['session']);
            $state['connection']->close(1012, 'Sideband service restart');
        }
        foreach ($connecting as $session) {
            $this->releaseForRestart($session);
        }

        Loop::stop();
    }

    private function beginConnection(VoiceRealtimeSession $session): void
    {
        $this->connecting[$session->id] = $session;

        try {
            $promise = $this->transport->connect(
                $session,
                fn (string $message) => $this->handleMessage($session->id, $message),
                fn (int $code, string $reason) => $this->handleClose($session->id, $code, $reason),
                fn (Throwable $error) => $this->handleError($session->id, $error),
            );
        } catch (Throwable $error) {
            unset($this->connecting[$session->id]);
            $this->disconnectSession($session, 'sideband_connect_failed', $error->getMessage());

            return;
        }

        $promise->then(
            function (RealtimeVoiceSidebandConnection $connection) use ($session): void {
                unset($this->connecting[$session->id]);
                if ($this->stopping) {
                    $this->releaseForRestart($session);
                    $connection->close(1012, 'Sideband service restart');

                    return;
                }

                try {
                    $ready = $this->sessions->markReady($session, $this->leaseOwner);
                    $abandoned = $this->commands->recoverAbandonedSending($ready, $this->leaseOwner);
                    foreach ($abandoned as $command) {
                        $this->reconcileCommandFailure($command);
                    }
                    $this->connections[$ready->id] = [
                        'session' => $ready,
                        'connection' => $connection,
                    ];
                    $this->readyRecoveryPending[$ready->id] = true;
                    $this->recoverReadySession($ready);
                    $this->recoverEvents($ready->id);
                    $this->drain($ready->id);
                } catch (Throwable $error) {
                    $connection->close(1008, 'Sideband lease unavailable');
                    $this->disconnectSession($session, 'sideband_activation_failed', $error->getMessage());
                    $this->safeLog('Could not activate a realtime sideband lease.', $session, $error);
                }
            },
            function (Throwable $error) use ($session): void {
                unset($this->connecting[$session->id]);
                if (! $this->stopping) {
                    $this->disconnectSession($session, 'sideband_connect_failed', $error->getMessage());
                }
            },
        );
    }

    private function handleMessage(int $sessionId, string $message): void
    {
        $state = $this->connections[$sessionId] ?? null;
        if ($this->stopping || $state === null) {
            return;
        }

        try {
            $providerEvent = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($providerEvent)) {
                throw new \UnexpectedValueException('Provider event was not a JSON object.');
            }

            if (! in_array($providerEvent['type'] ?? null, self::APPLICATION_EVENT_TYPES, true)) {
                return;
            }

            $this->events->record($state['session'], $providerEvent);
            $this->recoverEvents($sessionId);
        } catch (Throwable $error) {
            $this->safeLog('Realtime provider sent an invalid event.', $state['session'], $error);
            $this->dropConnection($sessionId, 'invalid_provider_event', $error->getMessage());
        }
    }

    private function handleClose(int $sessionId, int $code, string $reason): void
    {
        $state = $this->connections[$sessionId] ?? null;
        $connecting = $this->connecting[$sessionId] ?? null;
        unset($this->connections[$sessionId], $this->connecting[$sessionId], $this->readyRecoveryPending[$sessionId]);
        if ($this->stopping || ($state === null && $connecting === null)) {
            return;
        }

        $detail = sprintf('Provider sideband closed (%d): %s', $code, $reason);
        $this->disconnectSession($state['session'] ?? $connecting, 'sideband_closed', $detail);
    }

    private function handleError(int $sessionId, Throwable $error): void
    {
        if ($this->stopping) {
            return;
        }

        $this->dropConnection($sessionId, 'sideband_transport_failed', $error->getMessage());
    }

    private function drain(int $sessionId): void
    {
        $state = $this->connections[$sessionId] ?? null;
        if ($state === null) {
            return;
        }

        $limit = max(1, (int) config('services.voice_realtime.command_batch', 20));
        for ($sent = 0; $sent < $limit; $sent++) {
            $command = $this->commands->claimNext($state['session'], $this->leaseOwner);
            if ($command === null) {
                break;
            }

            try {
                $payload = json_encode($command->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (! $state['connection']->send($payload)) {
                    throw new \RuntimeException('The sideband socket rejected the command write.');
                }
                $this->commands->markSent($command, $this->leaseOwner);
            } catch (Throwable $error) {
                if ($command->fresh()?->status === VoiceRealtimeCommandStatus::Sending) {
                    $failed = $this->commands->markFailed($command, $this->leaseOwner, $error->getMessage());
                    $this->reconcileCommandFailure($failed);
                }
                $this->dropConnection($sessionId, 'sideband_command_failed', $error->getMessage());

                break;
            }
        }
    }

    private function recoverEvents(int $sessionId): void
    {
        $state = $this->connections[$sessionId] ?? null;
        if ($this->stopping || $state === null) {
            return;
        }

        $limit = max(1, (int) config('services.voice_realtime.event_batch', 20));
        for ($handled = 0; $handled < $limit; $handled++) {
            $event = $this->events->claimNext($state['session'], $this->leaseOwner);
            if ($event === null) {
                break;
            }

            try {
                if ($this->events->isExhausted($event)) {
                    $this->eventHandler->handleEventFailure($event);
                    $this->events->markFailureReconciled($event);
                } else {
                    $this->eventHandler->handle($event);
                    $this->events->markProcessed($event);
                }
            } catch (Throwable $error) {
                if ($this->events->isExhausted($event)) {
                    $this->events->markReconciliationFailed($event, $error->getMessage());
                } else {
                    $this->events->markFailed($event, $error->getMessage());
                }
                $this->safeLog('Realtime provider event handling failed.', $state['session'], $error);

                // Preserve provider event order. A later event cannot overtake
                // an earlier semantic or lifecycle event awaiting recovery.
                break;
            }
        }
    }

    private function dropConnection(int $sessionId, string $category, string $detail): void
    {
        $state = $this->connections[$sessionId] ?? null;
        $connecting = $this->connecting[$sessionId] ?? null;
        unset($this->connections[$sessionId], $this->connecting[$sessionId], $this->readyRecoveryPending[$sessionId]);
        if ($state === null && $connecting === null) {
            return;
        }

        $this->disconnectSession($state['session'] ?? $connecting, $category, $detail);
        if ($state !== null) {
            $state['connection']->close(1011, 'Sideband transport failure');
        }
    }

    private function disconnectSession(VoiceRealtimeSession $session, string $category, string $detail): void
    {
        try {
            $disconnected = $this->sessions->markDisconnected($session, $this->leaseOwner, $category, $detail);
            if ($disconnected->status->isTerminal()) {
                $this->reconcileSessionFailure($disconnected);
            }
        } catch (Throwable $error) {
            $this->safeLog('Could not persist realtime sideband disconnection.', $session, $error);
        }
    }

    private function reconcileCommandFailure(VoiceRealtimeCommand $command): void
    {
        try {
            $this->eventHandler->handleCommandFailure($command);
            $this->commands->markFailureReconciled($command);
        } catch (Throwable $error) {
            $session = $command->realtimeSession()->first();
            if ($session instanceof VoiceRealtimeSession) {
                $this->safeLog('Could not reconcile a failed realtime command.', $session, $error);
            }
        }
    }

    private function reconcileSessionFailure(VoiceRealtimeSession $session): void
    {
        try {
            $this->eventHandler->handleSessionFailure($session);
            $this->sessions->markFailureReconciled($session);
        } catch (Throwable $error) {
            $this->safeLog('Could not reconcile a failed realtime session.', $session, $error);
        }
    }

    private function recoverReadySession(VoiceRealtimeSession $session): void
    {
        try {
            $this->eventHandler->handleSessionReady($session);
            unset($this->readyRecoveryPending[$session->id]);
        } catch (Throwable $error) {
            // The authenticated transport remains usable. The idempotent
            // durable-final recovery is retried on the next heartbeat.
            $this->safeLog('Could not reconcile ready realtime session delivery.', $session, $error);
        }
    }

    private function releaseForRestart(VoiceRealtimeSession $session): void
    {
        try {
            $this->sessions->releaseForRestart($session, $this->leaseOwner);
        } catch (Throwable $error) {
            $this->safeLog('Could not release realtime sideband lease.', $session, $error);
        }
    }

    private function safeLog(string $message, VoiceRealtimeSession $session, Throwable $error): void
    {
        Log::warning($message, [
            'voice_realtime_session_id' => $session->public_id,
            'exception' => $error::class,
        ]);
    }
}
