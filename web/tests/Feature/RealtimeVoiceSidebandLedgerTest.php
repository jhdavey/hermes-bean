<?php

namespace Tests\Feature;

use App\Contracts\RealtimeVoiceProviderEventHandler;
use App\Contracts\RealtimeVoiceSidebandConnection;
use App\Contracts\RealtimeVoiceSidebandTransport;
use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceRealtimeSessionStatus;
use App\Exceptions\VoiceRealtimeLedgerException;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeEvent;
use App\Models\VoiceRealtimeSession;
use App\Services\RealtimeVoiceCommandService;
use App\Services\RealtimeVoiceEventService;
use App\Services\RealtimeVoiceSessionService;
use App\Services\RealtimeVoiceSidebandDaemon;
use App\Services\RealtimeVoiceSidebandRestartSignal;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use React\Promise\PromiseInterface;
use Tests\TestCase;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

class RealtimeVoiceSidebandLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_sideband_lease_can_be_taken_over_but_an_active_lease_cannot(): void
    {
        Carbon::setTestNow('2026-07-15 16:00:00');
        $session = $this->boundSession('lease@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);

        $first = $sessions->acquireLease($session, 'daemon-one', 2);
        $this->assertNotNull($first);
        $this->assertSame(VoiceRealtimeSessionStatus::Connecting, $first->status);
        $this->assertNull($sessions->acquireLease($session, 'daemon-two', 2));

        $ready = $sessions->markReady($first, 'daemon-one');
        Carbon::setTestNow(now()->addSeconds(3));
        $takeover = $sessions->acquireLease($ready, 'daemon-two', 2);

        $this->assertNotNull($takeover);
        $this->assertSame('daemon-two', $takeover->lease_owner);
        $this->assertSame(VoiceRealtimeSessionStatus::Reconnecting, $takeover->status);
        $this->assertSame(1, $takeover->reconnect_count);
        Carbon::setTestNow();
    }

    public function test_await_ready_fails_closed_until_a_live_daemon_lease_is_ready(): void
    {
        Carbon::setTestNow('2026-07-15 16:05:00');
        $session = $this->boundSession('await-ready@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);

        $this->assertNull($sessions->awaitReady($session, 0, 1));
        $connecting = $sessions->acquireLease($session, 'await-ready-daemon', 2);
        $this->assertNull($sessions->awaitReady($connecting, 0, 1));

        $ready = $sessions->markReady($connecting, 'await-ready-daemon');
        $this->assertSame($ready->id, $sessions->awaitReady($ready, 0, 1)?->id);

        Carbon::setTestNow(now()->addSeconds(3));
        $this->assertNull($sessions->awaitReady($ready, 0, 1));
        Carbon::setTestNow();
    }

    public function test_only_application_events_are_durable_while_audio_and_argument_deltas_are_discarded(): void
    {
        $session = $this->boundSession('event@example.com');
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $handler = new RecordingRealtimeVoiceEventHandler;
        $daemon = $this->daemon($transport, $handler, 'event-daemon');

        $daemon->tick();
        $transport->emit([
            'event_id' => 'evt_audio_1',
            'type' => 'response.output_audio.delta',
            'response_id' => 'resp_1',
            'delta' => 'base64-audio-must-not-persist',
            'response' => ['id' => 'resp_1', 'audio' => 'also-private'],
        ]);
        $transport->emit([
            'event_id' => 'evt_arguments_1',
            'type' => 'response.function_call_arguments.delta',
            'response_id' => 'resp_1',
            'delta' => '{"semantic_input":"private partial meaning"',
        ]);
        $relevant = [
            'event_id' => 'evt_response_1',
            'type' => 'response.created',
            'response' => ['id' => 'resp_1', 'metadata' => ['purpose' => 'semantic_plan']],
        ];
        $transport->emit($relevant);
        $transport->emit([
            'type' => 'response.created',
            'response' => ['metadata' => ['purpose' => 'semantic_plan'], 'id' => 'resp_1'],
            'event_id' => 'evt_response_1',
        ]);

        $event = VoiceRealtimeEvent::query()->sole();
        $this->assertSame(['evt_response_1'], $handler->eventIds);
        $this->assertNotNull($event->processed_at);
        $this->assertSame('response.created', $event->event_type);
        $this->assertStringNotContainsString('base64-audio', json_encode($event->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private partial meaning', json_encode($event->payload, JSON_THROW_ON_ERROR));
        $this->assertSame($session->user_id, $event->user_id);
        $this->assertSame($session->conversation_session_id, $event->conversation_session_id);
    }

    public function test_active_connection_is_not_reconnected_on_repeated_session_scans(): void
    {
        $session = $this->boundSession('single-connect@example.com');
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $daemon = $this->daemon($transport, new RecordingRealtimeVoiceEventHandler, 'single-connect-daemon');

        $daemon->tick();
        $daemon->tick();
        $daemon->tick();

        $this->assertSame(1, $transport->connectCalls);
        $this->assertSame(1, $session->refresh()->connect_attempts);
        $this->assertSame(VoiceRealtimeSessionStatus::Ready, $session->status);
    }

    public function test_ready_delivery_recovery_failure_keeps_transport_live_and_retries_on_heartbeat(): void
    {
        $session = $this->boundSession('ready-recovery-retry@example.com');
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $handler = new FailingReadyRealtimeVoiceEventHandler(1);
        $daemon = $this->daemon($transport, $handler, 'ready-recovery-daemon');

        $daemon->tick();

        $this->assertSame(1, $handler->readyCalls);
        $this->assertFalse($transport->connection->closed);
        $this->assertSame(VoiceRealtimeSessionStatus::Ready, $session->fresh()->status);

        $daemon->heartbeat();

        $this->assertSame(2, $handler->readyCalls);
        $this->assertFalse($transport->connection->closed);
        $this->assertSame(VoiceRealtimeSessionStatus::Ready, $session->fresh()->status);
    }

    public function test_failed_provider_event_is_recovered_after_daemon_restart_under_same_identity(): void
    {
        Carbon::setTestNow('2026-07-15 16:10:00');
        config()->set('services.voice_realtime.event_retry_delay_ms', 100);
        $session = $this->boundSession('event-recovery@example.com');
        $handler = new FailingRealtimeVoiceEventHandler(1);
        $firstTransport = new FakeRealtimeVoiceSidebandTransport;
        $firstDaemon = $this->daemon($firstTransport, $handler, 'event-daemon-one');

        $firstDaemon->tick();
        $firstTransport->emit([
            'event_id' => 'evt_recover_1',
            'type' => 'response.created',
            'response' => ['id' => 'resp_recover_1'],
        ]);

        $failed = VoiceRealtimeEvent::query()->sole();
        $this->assertSame(1, $failed->processing_attempts);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->processed_at);
        $firstDaemon->stop();

        Carbon::setTestNow(now()->addMilliseconds(101));
        $secondTransport = new FakeRealtimeVoiceSidebandTransport;
        $secondDaemon = $this->daemon($secondTransport, $handler, 'event-daemon-two');
        $secondDaemon->tick();

        $recovered = $failed->refresh();
        $this->assertSame(2, $recovered->processing_attempts);
        $this->assertNotNull($recovered->processed_at);
        $this->assertNull($recovered->failed_at);
        $this->assertSame(['evt_recover_1', 'evt_recover_1'], $handler->eventIds);
        Carbon::setTestNow();
    }

    public function test_provider_event_recovery_is_bounded_terminalized_and_preserves_head_of_line_order(): void
    {
        Carbon::setTestNow('2026-07-15 16:20:00');
        config()->set('services.voice_realtime.event_max_attempts', 2);
        config()->set('services.voice_realtime.event_retry_delay_ms', 100);
        $session = $this->boundSession('event-bounded@example.com');
        $handler = new FailingRealtimeVoiceEventHandler(PHP_INT_MAX);
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $daemon = $this->daemon($transport, $handler, 'event-bounded-daemon');

        $daemon->tick();
        $transport->emit(['event_id' => 'evt_poison', 'type' => 'response.created']);
        $transport->emit(['event_id' => 'evt_after_poison', 'type' => 'response.created']);
        Carbon::setTestNow(now()->addMilliseconds(101));
        $daemon->drainAll();
        Carbon::setTestNow(now()->addMilliseconds(201));
        $daemon->drainAll();

        $poison = VoiceRealtimeEvent::query()->where('provider_event_id', 'evt_poison')->firstOrFail();
        $later = VoiceRealtimeEvent::query()->where('provider_event_id', 'evt_after_poison')->firstOrFail();
        $this->assertSame(2, $poison->processing_attempts);
        $this->assertNotNull($poison->processed_at);
        $this->assertNotNull($poison->failed_at);
        $this->assertSame(['evt_poison'], $handler->failedEventIds);
        $this->assertSame(1, $later->processing_attempts);
        $this->assertNull($later->processed_at);
        $this->assertSame(['evt_poison', 'evt_poison', 'evt_after_poison'], $handler->eventIds);
        Carbon::setTestNow();
    }

    public function test_provider_connect_failure_releases_the_lease_for_a_bounded_reconnect(): void
    {
        $session = $this->boundSession('connect-failure@example.com');
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $transport->connectError = new \RuntimeException('Bearer sk-secret-provider-error-123456789');
        $daemon = $this->daemon($transport, new RecordingRealtimeVoiceEventHandler, 'connect-daemon');

        $daemon->tick();

        $failedAttempt = $session->refresh();
        $this->assertSame(VoiceRealtimeSessionStatus::Reconnecting, $failedAttempt->status);
        $this->assertSame('sideband_connect_failed', $failedAttempt->failure_category);
        $this->assertNull($failedAttempt->lease_owner);
        $this->assertStringNotContainsString('sk-secret-provider', (string) $failedAttempt->failure_detail);
        $this->assertNotNull($failedAttempt->reconnect_not_before_at);
        $this->assertFalse(app(RealtimeVoiceSessionService::class)
            ->claimableSessions()
            ->contains('id', $failedAttempt->id));
        Carbon::setTestNow($failedAttempt->reconnect_not_before_at->copy()->addMicrosecond());
        $this->assertTrue(app(RealtimeVoiceSessionService::class)
            ->claimableSessions()
            ->contains('id', $failedAttempt->id));
        Carbon::setTestNow();
    }

    public function test_conflicting_duplicate_provider_event_identity_fails_closed(): void
    {
        $session = $this->boundSession('event-conflict@example.com');
        $events = app(RealtimeVoiceEventService::class);
        $events->record($session, [
            'event_id' => 'evt_conflict',
            'type' => 'response.created',
            'response' => ['id' => 'resp_original'],
        ]);

        $this->expectException(VoiceRealtimeLedgerException::class);
        $events->record($session, [
            'event_id' => 'evt_conflict',
            'type' => 'response.created',
            'response' => ['id' => 'resp_conflicting'],
        ]);
    }

    public function test_durable_commands_are_idempotent_scoped_and_never_resent(): void
    {
        $session = $this->boundSession('command@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);
        $commands = app(RealtimeVoiceCommandService::class);
        $leased = $sessions->acquireLease($session, 'command-daemon');
        $ready = $sessions->markReady($leased, 'command-daemon');

        $first = $commands->enqueue(
            $ready,
            'cancel:turn-1',
            VoiceRealtimeCommandType::ResponseCancel,
            ['response_id' => 'resp_1'],
        );
        $duplicate = $commands->enqueue(
            $ready,
            'cancel:turn-1',
            VoiceRealtimeCommandType::ResponseCancel,
            ['response_id' => 'resp_1'],
        );
        $this->assertSame($first->id, $duplicate->id);

        try {
            $commands->enqueue(
                $ready,
                'cancel:turn-1',
                VoiceRealtimeCommandType::ResponseCancel,
                ['response_id' => 'resp_conflict'],
            );
            $this->fail('Conflicting command reuse should fail closed.');
        } catch (VoiceRealtimeLedgerException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(VoiceRealtimeSessionStatus::Ready, $ready->refresh()->status);
        $this->assertSame('command-daemon', $ready->lease_owner);
        $this->assertTrue($ready->lease_expires_at->isFuture());
        $this->assertSame(1, $ready->commands()->where('status', VoiceRealtimeCommandStatus::Queued->value)->count());
        $claimed = $commands->claimNext($ready, 'command-daemon');
        $this->assertNotNull($claimed);
        $this->assertSame(VoiceRealtimeCommandStatus::Sending, $claimed->status);
        $sent = $commands->markSent($claimed, 'command-daemon');
        $this->assertSame(VoiceRealtimeCommandStatus::Sent, $sent->status);
        $this->assertNull($commands->claimNext($ready, 'command-daemon'));

        $this->expectException(VoiceRealtimeLedgerException::class);
        $commands->enqueue(
            $ready,
            'unsafe:audio',
            VoiceRealtimeCommandType::ConversationItemCreate,
            ['item' => ['content' => [['type' => 'input_audio', 'audio' => 'raw-bytes']]]],
        );
    }

    public function test_daemon_sends_each_command_once_and_fails_closed_on_transport_write_failure(): void
    {
        $session = $this->boundSession('send@example.com');
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $handler = new RecordingRealtimeVoiceEventHandler;
        $daemon = $this->daemon($transport, $handler, 'send-daemon');
        $commands = app(RealtimeVoiceCommandService::class);

        $daemon->tick();
        $ready = $session->refresh();
        $commands->enqueue(
            $ready,
            'session:update:1',
            VoiceRealtimeCommandType::SessionUpdate,
            ['session' => ['type' => 'realtime', 'instructions' => 'Use Hermes.']],
        );
        $daemon->drainAll();
        $daemon->drainAll();

        $this->assertCount(1, $transport->connection->sent);
        $this->assertSame(
            VoiceRealtimeCommandStatus::Sent,
            $ready->commands()->where('command_id', 'session:update:1')->firstOrFail()->status,
        );

        $commands->enqueue(
            $ready,
            'response:create:2',
            VoiceRealtimeCommandType::ResponseCreate,
            ['response' => ['output_modalities' => ['audio']]],
        );
        $transport->connection->acceptWrites = false;
        $daemon->drainAll();

        $failed = $ready->commands()->where('command_id', 'response:create:2')->firstOrFail();
        $this->assertSame(VoiceRealtimeCommandStatus::Failed, $failed->status);
        $this->assertSame(VoiceRealtimeSessionStatus::Reconnecting, $session->refresh()->status);
    }

    public function test_abandoned_sending_command_is_not_resent_and_is_durably_reconciled(): void
    {
        Carbon::setTestNow('2026-07-15 16:30:00');
        $session = $this->boundSession('command-takeover@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);
        $commands = app(RealtimeVoiceCommandService::class);
        $ready = $sessions->markReady(
            $sessions->acquireLease($session, 'crashed-daemon', 2),
            'crashed-daemon',
        );
        $queued = $commands->enqueue(
            $ready,
            'cancel:unknown-delivery',
            VoiceRealtimeCommandType::ResponseCancel,
            ['response_id' => 'resp_unknown_delivery'],
        );
        $this->assertSame(
            VoiceRealtimeCommandStatus::Sending,
            $commands->claimNext($ready, 'crashed-daemon')?->status,
        );

        Carbon::setTestNow(now()->addSeconds(3));
        $transport = new FakeRealtimeVoiceSidebandTransport;
        $handler = new RecordingRealtimeVoiceEventHandler;
        $daemon = $this->daemon($transport, $handler, 'takeover-daemon');
        $daemon->tick();

        $failed = $queued->fresh();
        $this->assertSame(VoiceRealtimeCommandStatus::Failed, $failed->status);
        $this->assertStringStartsWith('reconciled: delivery_unknown:', (string) $failed->error);
        $this->assertSame(['cancel:unknown-delivery'], $handler->failedCommandIds);
        $this->assertSame([], $transport->connection->sent);
        $daemon->drainAll();
        $this->assertSame(['cancel:unknown-delivery'], $handler->failedCommandIds);
        Carbon::setTestNow();
    }

    public function test_same_daemon_reconnect_also_fails_an_uncertain_in_flight_command_without_resending(): void
    {
        config()->set('services.voice_realtime.reconnect_delay_ms', 0);
        $session = $this->boundSession('command-same-owner-reconnect@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);
        $commands = app(RealtimeVoiceCommandService::class);
        $ready = $sessions->markReady(
            $sessions->acquireLease($session, 'same-daemon'),
            'same-daemon',
        );
        $queued = $commands->enqueue(
            $ready,
            'cancel:same-owner-unknown-delivery',
            VoiceRealtimeCommandType::ResponseCancel,
            ['response_id' => 'resp_same_owner_unknown_delivery'],
        );
        $this->assertSame(
            VoiceRealtimeCommandStatus::Sending,
            $commands->claimNext($ready, 'same-daemon')?->status,
        );
        $sessions->markDisconnected(
            $ready,
            'same-daemon',
            'sideband_closed',
            'Synthetic reconnect after an uncertain write.',
        );

        $transport = new FakeRealtimeVoiceSidebandTransport;
        $handler = new RecordingRealtimeVoiceEventHandler;
        $daemon = $this->daemon($transport, $handler, 'same-daemon');
        $daemon->tick();

        $failed = $queued->fresh();
        $this->assertSame(VoiceRealtimeCommandStatus::Failed, $failed->status);
        $this->assertStringStartsWith('reconciled: delivery_unknown:', (string) $failed->error);
        $this->assertSame(['cancel:same-owner-unknown-delivery'], $handler->failedCommandIds);
        $this->assertSame([], $transport->connection->sent);
    }

    public function test_reconnect_budget_terminalizes_repeated_transport_failure(): void
    {
        config()->set('services.voice_realtime.max_reconnect_attempts', 1);
        config()->set('services.voice_realtime.reconnect_delay_ms', 0);
        $session = $this->boundSession('failure@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);

        $first = $sessions->acquireLease($session, 'daemon-one');
        $sessions->markReady($first, 'daemon-one');
        $reconnecting = $sessions->markDisconnected(
            $session,
            'daemon-one',
            'sideband_closed',
            'Bearer sk-this-secret-must-be-redacted-123456789',
        );
        $this->assertSame(VoiceRealtimeSessionStatus::Reconnecting, $reconnecting->status);
        $this->assertStringNotContainsString('sk-this-secret', (string) $reconnecting->failure_detail);

        $second = $sessions->acquireLease($reconnecting, 'daemon-two');
        $sessions->markReady($second, 'daemon-two');
        $failed = $sessions->markDisconnected($session, 'daemon-two', 'sideband_closed', 'again');

        $this->assertSame(VoiceRealtimeSessionStatus::Failed, $failed->status);
        $this->assertNotNull($failed->closed_at);
        $this->assertNull($failed->lease_owner);
    }

    public function test_failed_session_reconciliation_survives_a_daemon_crash_boundary(): void
    {
        config()->set('services.voice_realtime.max_reconnect_attempts', 0);
        $session = $this->boundSession('session-reconcile@example.com');
        $sessions = app(RealtimeVoiceSessionService::class);
        $ready = $sessions->markReady(
            $sessions->acquireLease($session, 'failed-session-daemon'),
            'failed-session-daemon',
        );
        $failed = $sessions->markDisconnected(
            $ready,
            'failed-session-daemon',
            'sideband_closed',
            'Provider transport closed.',
        );
        $this->assertSame(VoiceRealtimeSessionStatus::Failed, $failed->status);
        $this->assertNull(data_get($failed->metadata, 'failure_reconciled_at'));

        $handler = new RecordingRealtimeVoiceEventHandler;
        $daemon = $this->daemon(new FakeRealtimeVoiceSidebandTransport, $handler, 'reconcile-daemon');
        $daemon->tick();
        $daemon->tick();

        $this->assertSame([$session->public_id], $handler->failedSessionIds);
        $this->assertNotNull(data_get($session->fresh()->metadata, 'failure_reconciled_at'));
    }

    public function test_realtime_session_lookup_and_command_scope_are_user_isolated(): void
    {
        $session = $this->boundSession('owner@example.com');
        $otherUser = User::factory()->create();
        $sessions = app(RealtimeVoiceSessionService::class);

        $this->assertSame($session->id, $sessions->findOwned(
            $session->user,
            $session->public_id,
            $session->conversation_session_id,
        )->id);

        $this->expectException(ModelNotFoundException::class);
        $sessions->findOwned($otherUser, $session->public_id);
    }

    public function test_restart_signal_changes_and_once_command_has_a_bounded_empty_pass(): void
    {
        $restart = app(RealtimeVoiceSidebandRestartSignal::class);
        $before = $restart->current();
        $this->artisan('voice:realtime-sidebands-restart')->assertSuccessful();
        $this->assertNotSame($before, $restart->current());

        config()->set('services.voice_realtime.once_grace_ms', 50);
        $this->app->instance(RealtimeVoiceProviderEventHandler::class, new RecordingRealtimeVoiceEventHandler);
        $this->app->instance(RealtimeVoiceSidebandTransport::class, new FakeRealtimeVoiceSidebandTransport);
        $this->artisan('voice:realtime-sidebands', ['--once' => true])->assertSuccessful();
    }

    private function boundSession(string $email): VoiceRealtimeSession
    {
        $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $session = app(RealtimeVoiceSessionService::class)->createPending(
            $user,
            $conversation,
            'gpt-realtime-2.1',
            'alloy',
        );

        return app(RealtimeVoiceSessionService::class)->bindProviderCall($session, 'rtc_'.$session->public_id);
    }

    private function daemon(
        FakeRealtimeVoiceSidebandTransport $transport,
        RecordingRealtimeVoiceEventHandler $handler,
        string $owner,
    ): RealtimeVoiceSidebandDaemon {
        return new RealtimeVoiceSidebandDaemon(
            app(RealtimeVoiceSessionService::class),
            app(RealtimeVoiceEventService::class),
            app(RealtimeVoiceCommandService::class),
            $transport,
            $handler,
            app(RealtimeVoiceSidebandRestartSignal::class),
            $owner,
        );
    }
}

class FakeRealtimeVoiceSidebandTransport implements RealtimeVoiceSidebandTransport
{
    public FakeRealtimeVoiceSidebandConnection $connection;

    public ?Throwable $connectError = null;

    public int $connectCalls = 0;

    private ?Closure $onMessage = null;

    private ?Closure $onClose = null;

    private ?Closure $onError = null;

    public function __construct()
    {
        $this->connection = new FakeRealtimeVoiceSidebandConnection;
    }

    public function connect(
        VoiceRealtimeSession $session,
        Closure $onMessage,
        Closure $onClose,
        Closure $onError,
    ): PromiseInterface {
        $this->connectCalls++;
        $this->onMessage = $onMessage;
        $this->onClose = $onClose;
        $this->onError = $onError;

        if ($this->connectError !== null) {
            return reject($this->connectError);
        }

        return resolve($this->connection);
    }

    /** @param array<string, mixed> $event */
    public function emit(array $event): void
    {
        ($this->onMessage)(json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        ($this->onClose)($code, $reason);
    }

    public function fail(Throwable $error): void
    {
        ($this->onError)($error);
    }
}

class FakeRealtimeVoiceSidebandConnection implements RealtimeVoiceSidebandConnection
{
    /** @var array<int, string> */
    public array $sent = [];

    public bool $acceptWrites = true;

    public bool $closed = false;

    public function send(string $payload): bool
    {
        if (! $this->acceptWrites) {
            return false;
        }

        $this->sent[] = $payload;

        return true;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->closed = true;
    }
}

class RecordingRealtimeVoiceEventHandler implements RealtimeVoiceProviderEventHandler
{
    /** @var array<int, string> */
    public array $eventIds = [];

    /** @var array<int, string> */
    public array $failedEventIds = [];

    /** @var array<int, string> */
    public array $failedCommandIds = [];

    /** @var array<int, string> */
    public array $failedSessionIds = [];

    public function handle(VoiceRealtimeEvent $event): void
    {
        $this->eventIds[] = $event->provider_event_id;
    }

    public function handleSessionReady(VoiceRealtimeSession $session): void
    {
        // No application recovery is needed for the transport-only fake.
    }

    public function handleEventFailure(VoiceRealtimeEvent $event): void
    {
        $this->failedEventIds[] = $event->provider_event_id;
    }

    public function handleCommandFailure(VoiceRealtimeCommand $command): void
    {
        $this->failedCommandIds[] = $command->command_id;
    }

    public function handleSessionFailure(VoiceRealtimeSession $session): void
    {
        $this->failedSessionIds[] = $session->public_id;
    }
}

class FailingRealtimeVoiceEventHandler extends RecordingRealtimeVoiceEventHandler
{
    public function __construct(private int $remainingFailures) {}

    public function handle(VoiceRealtimeEvent $event): void
    {
        parent::handle($event);
        if ($this->remainingFailures > 0) {
            $this->remainingFailures--;

            throw new \RuntimeException('Synthetic application handler failure.');
        }
    }
}

class FailingReadyRealtimeVoiceEventHandler extends RecordingRealtimeVoiceEventHandler
{
    public int $readyCalls = 0;

    public function __construct(private int $remainingFailures) {}

    public function handleSessionReady(VoiceRealtimeSession $session): void
    {
        $this->readyCalls++;
        if ($this->remainingFailures > 0) {
            $this->remainingFailures--;

            throw new \RuntimeException('Synthetic ready recovery failure.');
        }
    }
}
