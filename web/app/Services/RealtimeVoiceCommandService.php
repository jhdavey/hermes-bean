<?php

namespace App\Services;

use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceRealtimeSessionStatus;
use App\Exceptions\VoiceRealtimeLedgerException;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RealtimeVoiceCommandService
{
    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(
        VoiceRealtimeSession $session,
        string $commandId,
        VoiceRealtimeCommandType $type,
        array $payload = [],
        ?VoiceTurn $turn = null,
        ?string $purpose = null,
        ?string $speechItemId = null,
        ?int $controllerGeneration = null,
        ?string $approvedText = null,
    ): VoiceRealtimeCommand {
        $id = trim($commandId);
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $id)) {
            throw new VoiceRealtimeLedgerException('The realtime command identifier is invalid.');
        }
        if ($session->status->isTerminal()) {
            throw new VoiceRealtimeLedgerException('Commands cannot be queued for a terminal realtime session.');
        }

        $this->assertTurnScope($session, $turn);
        $this->assertNoAudioBytes($payload);
        $normalized = ['event_id' => $id, 'type' => $type->value, ...$payload];
        if (($normalized['event_id'] ?? null) !== $id || ($normalized['type'] ?? null) !== $type->value) {
            throw new VoiceRealtimeLedgerException('Command identity and type are application-owned.');
        }
        $this->validateProviderShape($type, $normalized);

        $approvedTextHash = $approvedText === null
            ? null
            : hash('sha256', $approvedText);
        $attributes = [
            'voice_turn_id' => $turn?->id,
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->conversation_session_id,
            'command_type' => $type,
            'purpose' => $this->nullableString($purpose, 80),
            'speech_item_id' => $this->nullableString($speechItemId, 191),
            'controller_generation' => max(0, $controllerGeneration ?? $session->controller_generation),
            'approved_text_hash' => $approvedTextHash,
            'payload' => $normalized,
            'status' => VoiceRealtimeCommandStatus::Queued,
            'attempts' => 0,
            'available_at' => now(),
        ];

        return DB::transaction(function () use ($session, $id, $attributes): VoiceRealtimeCommand {
            $lockedSession = VoiceRealtimeSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($lockedSession->status->isTerminal()) {
                throw new VoiceRealtimeLedgerException('Commands cannot be queued for a terminal realtime session.');
            }

            $command = VoiceRealtimeCommand::query()->firstOrCreate(
                [
                    'voice_realtime_session_id' => $lockedSession->id,
                    'command_id' => $id,
                ],
                $attributes,
            );

            if (! $command->wasRecentlyCreated && ! $this->matches($command, $attributes)) {
                throw new VoiceRealtimeLedgerException('A realtime command identifier was reused with conflicting data.');
            }

            return $command;
        });
    }

    public function claimNext(VoiceRealtimeSession $session, string $leaseOwner): ?VoiceRealtimeCommand
    {
        return DB::transaction(function () use ($session, $leaseOwner): ?VoiceRealtimeCommand {
            /** @var VoiceRealtimeSession|null $lockedSession */
            $lockedSession = VoiceRealtimeSession::query()->lockForUpdate()->find($session->id);
            if ($lockedSession === null
                || $lockedSession->status !== VoiceRealtimeSessionStatus::Ready
                || $lockedSession->lease_owner === null
                || ! hash_equals($lockedSession->lease_owner, $leaseOwner)
                || $lockedSession->lease_expires_at === null
                || ! $lockedSession->lease_expires_at->isFuture()) {
                return null;
            }

            /** @var VoiceRealtimeCommand|null $command */
            $command = VoiceRealtimeCommand::query()
                ->where('voice_realtime_session_id', $lockedSession->id)
                ->where('status', VoiceRealtimeCommandStatus::Queued->value)
                ->where('available_at', '<=', now()->format('Y-m-d H:i:s.u'))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($command === null) {
                return null;
            }

            $command->forceFill([
                'status' => VoiceRealtimeCommandStatus::Sending,
                'attempts' => $command->attempts + 1,
                'sending_lease_owner' => $leaseOwner,
                'sending_at' => now(),
                'error' => null,
            ])->save();

            return $command->refresh();
        });
    }

    public function markSent(VoiceRealtimeCommand $command, string $leaseOwner): VoiceRealtimeCommand
    {
        return DB::transaction(function () use ($command, $leaseOwner): VoiceRealtimeCommand {
            $locked = VoiceRealtimeCommand::query()->lockForUpdate()->findOrFail($command->id);
            if (in_array($locked->status, [
                VoiceRealtimeCommandStatus::Sent,
                VoiceRealtimeCommandStatus::Acknowledged,
            ], true)) {
                return $locked;
            }
            if ($locked->status !== VoiceRealtimeCommandStatus::Sending
                || $locked->sending_lease_owner === null
                || ! hash_equals($locked->sending_lease_owner, $leaseOwner)) {
                throw new VoiceRealtimeLedgerException('The realtime command is not owned by this sideband lease.');
            }

            $locked->forceFill([
                'status' => VoiceRealtimeCommandStatus::Sent,
                'sent_at' => $locked->sent_at ?? now(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function markFailed(VoiceRealtimeCommand $command, string $leaseOwner, string $error): VoiceRealtimeCommand
    {
        return $this->mutateSending($command, $leaseOwner, [
            'status' => VoiceRealtimeCommandStatus::Failed,
            'failed_at' => now(),
            'error' => 'delivery_failed: '.$this->privacy->sanitizeTranscript($error),
        ]);
    }

    public function acknowledge(VoiceRealtimeCommand $command, ?string $providerResponseId = null): VoiceRealtimeCommand
    {
        return DB::transaction(function () use ($command, $providerResponseId): VoiceRealtimeCommand {
            /** @var VoiceRealtimeCommand $locked */
            $locked = VoiceRealtimeCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($locked->status === VoiceRealtimeCommandStatus::Acknowledged) {
                $normalizedResponseId = $this->nullableString($providerResponseId, 191);
                if ($normalizedResponseId !== null
                    && $locked->provider_response_id !== null
                    && ! hash_equals($locked->provider_response_id, $normalizedResponseId)) {
                    throw new VoiceRealtimeLedgerException('A realtime command was acknowledged by conflicting provider responses.');
                }

                return $locked;
            }
            if (! in_array($locked->status, [
                VoiceRealtimeCommandStatus::Sending,
                VoiceRealtimeCommandStatus::Sent,
            ], true)) {
                throw new VoiceRealtimeLedgerException('Only a sent realtime command can be acknowledged.');
            }

            $locked->forceFill([
                'status' => VoiceRealtimeCommandStatus::Acknowledged,
                'sent_at' => $locked->sent_at ?? now(),
                'acknowledged_at' => now(),
                'provider_response_id' => $this->nullableString($providerResponseId, 191),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * The browser proved that a server-authorized speech response could not
     * become usable playback. This is already reconciled client evidence, so
     * it must not re-enter transport-write failure reconciliation.
     */
    public function markPlaybackFailed(VoiceRealtimeCommand $command): VoiceRealtimeCommand
    {
        return DB::transaction(function () use ($command): VoiceRealtimeCommand {
            $locked = VoiceRealtimeCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($locked->status === VoiceRealtimeCommandStatus::Failed) {
                return $locked;
            }
            if (! in_array($locked->purpose, ['acknowledgement', 'clarification', 'final'], true)
                || $locked->speech_item_id === null
                || $locked->status === VoiceRealtimeCommandStatus::Canceled) {
                throw new VoiceRealtimeLedgerException('Only an authorized speech command can record playback failure.');
            }

            $locked->forceFill([
                'status' => VoiceRealtimeCommandStatus::Failed,
                'failed_at' => now(),
                'error' => 'reconciled: playback_failed: Browser playback failed before usable delivery.',
            ])->save();

            return $locked->refresh();
        });
    }

    /** @return Collection<int, VoiceRealtimeCommand> */
    public function recoverAbandonedSending(
        VoiceRealtimeSession $session,
        string $currentLeaseOwner,
    ): Collection {
        if (trim($currentLeaseOwner) === '') {
            throw new VoiceRealtimeLedgerException('A sideband lease owner is required for command recovery.');
        }

        // A newly activated socket cannot prove whether any prior in-flight
        // write reached the provider, even when the daemon process retained
        // the same lease identity. Never resend that uncertain command.
        $abandoned = VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('status', VoiceRealtimeCommandStatus::Sending->value)
            ->orderBy('id')
            ->get();
        if ($abandoned->isEmpty()) {
            return $abandoned;
        }

        $updated = VoiceRealtimeCommand::query()
            ->whereKey($abandoned->modelKeys())
            ->where('status', VoiceRealtimeCommandStatus::Sending->value)
            ->update([
                'status' => VoiceRealtimeCommandStatus::Failed->value,
                'failed_at' => now(),
                'error' => 'delivery_unknown: Delivery outcome was unknown after sideband lease takeover; command was not resent.',
                'updated_at' => now(),
            ]);

        return $updated > 0
            ? VoiceRealtimeCommand::query()->whereKey($abandoned->modelKeys())->orderBy('id')->get()
            : new Collection;
    }

    /** @return Collection<int, VoiceRealtimeCommand> */
    public function failuresAwaitingReconciliation(
        VoiceRealtimeSession $session,
        int $limit = 20,
    ): Collection {
        return VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('status', VoiceRealtimeCommandStatus::Failed->value)
            ->where(function ($query): void {
                $query->where('error', 'like', 'delivery_failed:%')
                    ->orWhere('error', 'like', 'delivery_unknown:%');
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function markFailureReconciled(VoiceRealtimeCommand $command): VoiceRealtimeCommand
    {
        return DB::transaction(function () use ($command): VoiceRealtimeCommand {
            $locked = VoiceRealtimeCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($locked->status !== VoiceRealtimeCommandStatus::Failed
                || str_starts_with((string) $locked->error, 'reconciled:')) {
                return $locked;
            }

            $locked->forceFill([
                'error' => 'reconciled: '.mb_substr((string) $locked->error, 0, 64000),
            ])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    private function matches(VoiceRealtimeCommand $command, array $attributes): bool
    {
        return (int) ($command->voice_turn_id ?? 0) === (int) ($attributes['voice_turn_id'] ?? 0)
            && (int) $command->user_id === (int) $attributes['user_id']
            && (int) ($command->workspace_id ?? 0) === (int) ($attributes['workspace_id'] ?? 0)
            && (int) $command->conversation_session_id === (int) $attributes['conversation_session_id']
            && $command->command_type === $attributes['command_type']
            && $command->purpose === $attributes['purpose']
            && $command->speech_item_id === $attributes['speech_item_id']
            && $command->controller_generation === $attributes['controller_generation']
            && $command->approved_text_hash === $attributes['approved_text_hash']
            && $this->payloadHash($command->payload) === $this->payloadHash($attributes['payload']);
    }

    private function mutateSending(
        VoiceRealtimeCommand $command,
        string $leaseOwner,
        array $attributes,
    ): VoiceRealtimeCommand {
        return DB::transaction(function () use ($command, $leaseOwner, $attributes): VoiceRealtimeCommand {
            /** @var VoiceRealtimeCommand $locked */
            $locked = VoiceRealtimeCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($locked->status !== VoiceRealtimeCommandStatus::Sending
                || $locked->sending_lease_owner === null
                || ! hash_equals($locked->sending_lease_owner, $leaseOwner)) {
                throw new VoiceRealtimeLedgerException('The realtime command is not owned by this sideband lease.');
            }

            $locked->forceFill($attributes)->save();

            return $locked->refresh();
        });
    }

    private function assertTurnScope(VoiceRealtimeSession $session, ?VoiceTurn $turn): void
    {
        if ($turn === null) {
            return;
        }

        $sameScope = (int) $turn->user_id === (int) $session->user_id
            && (int) $turn->conversation_session_id === (int) $session->conversation_session_id
            && (int) ($turn->workspace_id ?? 0) === (int) ($session->workspace_id ?? 0)
            && ($turn->realtime_session_id === null
                || (int) $turn->realtime_session_id === (int) $session->id);
        if (! $sameScope) {
            throw new VoiceRealtimeLedgerException('The voice turn and realtime session scopes do not match.');
        }
    }

    /** @param array<string|int, mixed> $payload */
    private function assertNoAudioBytes(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if ($this->privacy->isRawAudioKey((string) $key)) {
                throw new VoiceRealtimeLedgerException('Raw audio bytes cannot enter the durable command ledger.');
            }
            if (is_array($value)) {
                $this->assertNoAudioBytes($value);
            }
        }

        $contentType = strtolower((string) data_get($payload, 'item.content.0.type', ''));
        if (str_contains($contentType, 'audio')) {
            throw new VoiceRealtimeLedgerException('Audio content cannot enter the durable command ledger.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateProviderShape(VoiceRealtimeCommandType $type, array $payload): void
    {
        $requiredObject = match ($type) {
            VoiceRealtimeCommandType::SessionUpdate => 'session',
            VoiceRealtimeCommandType::ResponseCreate => 'response',
            VoiceRealtimeCommandType::ConversationItemCreate => 'item',
            VoiceRealtimeCommandType::ResponseCancel,
            VoiceRealtimeCommandType::OutputAudioBufferClear => null,
        };
        if ($requiredObject !== null && ! is_array($payload[$requiredObject] ?? null)) {
            throw new VoiceRealtimeLedgerException("{$type->value} requires a {$requiredObject} object.");
        }

        if ($type === VoiceRealtimeCommandType::SessionUpdate
            && data_get($payload, 'session.type') !== 'realtime') {
            throw new VoiceRealtimeLedgerException('session.update requires a realtime session object.');
        }
        if ($type === VoiceRealtimeCommandType::ResponseCreate) {
            $modalities = data_get($payload, 'response.output_modalities');
            if ($modalities !== null
                && (! is_array($modalities)
                    || ! array_is_list($modalities)
                    || count($modalities) !== 1
                    || ! in_array($modalities[0], ['audio', 'text'], true))) {
                throw new VoiceRealtimeLedgerException('response.create requires exactly one supported output modality.');
            }
        }
        if ($type === VoiceRealtimeCommandType::ConversationItemCreate
            && data_get($payload, 'item.type') === 'function_call_output'
            && (trim((string) data_get($payload, 'item.call_id')) === ''
                || ! is_string(data_get($payload, 'item.output')))) {
            throw new VoiceRealtimeLedgerException('A function-call output requires a call ID and string output.');
        }
        if ($type === VoiceRealtimeCommandType::ResponseCancel
            && trim((string) ($payload['response_id'] ?? '')) === '') {
            throw new VoiceRealtimeLedgerException('response.cancel requires the targeted provider response ID.');
        }
        if ($type === VoiceRealtimeCommandType::OutputAudioBufferClear
            && array_diff(array_keys($payload), ['event_id', 'type']) !== []) {
            throw new VoiceRealtimeLedgerException('output_audio_buffer.clear accepts no additional fields.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function nullableString(?string $value, int $maxLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
