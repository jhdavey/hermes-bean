<?php

namespace App\Services;

use App\Enums\VoiceRealtimeSessionStatus;
use App\Exceptions\VoiceRealtimeLedgerException;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealtimeVoiceSessionService
{
    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    public function createPending(
        User $user,
        ConversationSession $conversation,
        string $model,
        string $voice,
        int $controllerGeneration = 0,
        array $metadata = [],
    ): VoiceRealtimeSession {
        $this->assertConversationOwnership($user, $conversation);

        return VoiceRealtimeSession::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'workspace_id' => $conversation->workspace_id,
            'conversation_session_id' => $conversation->id,
            'provider_model' => mb_substr(trim($model), 0, 100),
            'voice' => mb_substr(trim($voice), 0, 80),
            'status' => VoiceRealtimeSessionStatus::Pending,
            'controller_generation' => max(0, $controllerGeneration),
            'metadata' => $this->privacy->sanitizeDiagnosticPayload($metadata),
        ]);
    }

    public function bindProviderCall(VoiceRealtimeSession $session, string $providerCallId): VoiceRealtimeSession
    {
        $callId = trim($providerCallId);
        if ($callId === '' || mb_strlen($callId) > 191) {
            throw new VoiceRealtimeLedgerException('The provider call identifier is invalid.');
        }

        return DB::transaction(function () use ($session, $callId): VoiceRealtimeSession {
            /** @var VoiceRealtimeSession $locked */
            $locked = VoiceRealtimeSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->provider_call_id !== null && ! hash_equals($locked->provider_call_id, $callId)) {
                throw new VoiceRealtimeLedgerException('The realtime session is already bound to another provider call.');
            }

            $collision = VoiceRealtimeSession::query()
                ->where('provider_call_id', $callId)
                ->whereKeyNot($locked->id)
                ->exists();
            if ($collision) {
                throw new VoiceRealtimeLedgerException('The provider call is already bound to another realtime session.');
            }

            $locked->forceFill(['provider_call_id' => $callId])->save();

            return $locked->refresh();
        });
    }

    public function findOwned(
        User $user,
        string $publicId,
        ?int $conversationSessionId = null,
    ): VoiceRealtimeSession {
        return VoiceRealtimeSession::query()
            ->ownedBy($user)
            ->where('public_id', $publicId)
            ->when($conversationSessionId !== null, fn ($query) => $query->where(
                'conversation_session_id',
                $conversationSessionId,
            ))
            ->firstOrFail();
    }

    /**
     * Wait briefly for the daemon-owned sideband lease to become usable.
     * Admission callers may release activated PCM only when this returns a
     * ready session; a timeout, terminal session, expired lease, or deleted
     * session fails closed.
     */
    public function awaitReady(
        VoiceRealtimeSession|int $session,
        int $timeoutMs = 1000,
        int $pollIntervalMs = 20,
    ): ?VoiceRealtimeSession {
        $sessionId = $session instanceof VoiceRealtimeSession ? $session->id : $session;
        $timeoutNanoseconds = max(0, $timeoutMs) * 1_000_000;
        $deadline = hrtime(true) + $timeoutNanoseconds;
        $pollMicroseconds = max(1, $pollIntervalMs) * 1000;

        do {
            $fresh = VoiceRealtimeSession::query()->find($sessionId);
            if (! $fresh instanceof VoiceRealtimeSession || $fresh->status->isTerminal()) {
                return null;
            }
            if ($fresh->status === VoiceRealtimeSessionStatus::Ready
                && $fresh->lease_owner !== null
                && $fresh->lease_expires_at?->isFuture()) {
                return $fresh;
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                return null;
            }

            usleep((int) min($pollMicroseconds, max(1, intdiv($remainingNanoseconds, 1000))));
        } while (true);
    }

    public function acquireLease(
        VoiceRealtimeSession|int $session,
        string $leaseOwner,
        ?int $ttlSeconds = null,
    ): ?VoiceRealtimeSession {
        $owner = $this->normalizeLeaseOwner($leaseOwner);
        $ttl = $this->leaseSeconds($ttlSeconds);

        return DB::transaction(function () use ($session, $owner, $ttl): ?VoiceRealtimeSession {
            /** @var VoiceRealtimeSession|null $locked */
            $locked = VoiceRealtimeSession::query()
                ->lockForUpdate()
                ->find($session instanceof VoiceRealtimeSession ? $session->id : $session);
            if ($locked === null
                || $locked->provider_call_id === null
                || ! $locked->status->mayConnect()
                || ($locked->reconnect_not_before_at !== null && $locked->reconnect_not_before_at->isFuture())) {
                return null;
            }

            $now = now();
            $leaseActive = $locked->lease_owner !== null
                && $locked->lease_expires_at !== null
                && $locked->lease_expires_at->isAfter($now);
            if ($leaseActive && ! hash_equals($locked->lease_owner, $owner)) {
                return null;
            }

            $expiredTakeover = $locked->lease_owner !== null
                && ! hash_equals($locked->lease_owner, $owner)
                && ($locked->lease_expires_at === null || ! $locked->lease_expires_at->isAfter($now));
            $wasConnected = in_array($locked->status, [
                VoiceRealtimeSessionStatus::Connecting,
                VoiceRealtimeSessionStatus::Ready,
            ], true);
            $expiredConnection = $wasConnected
                && $locked->lease_owner !== null
                && ! $leaseActive;
            $reconnectCount = $locked->reconnect_count + ($expiredConnection ? 1 : 0);
            if ($reconnectCount > max(0, (int) config('services.voice_realtime.max_reconnect_attempts', 3))) {
                $locked->forceFill([
                    'status' => VoiceRealtimeSessionStatus::Failed,
                    'reconnect_count' => $reconnectCount,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'reconnect_not_before_at' => null,
                    'closed_at' => $now,
                    'failure_category' => 'sideband_lease_expired',
                    'failure_detail' => 'The realtime sideband reconnect budget was exhausted.',
                ])->save();

                return null;
            }

            $locked->forceFill([
                'status' => ($expiredTakeover || $wasConnected)
                    ? VoiceRealtimeSessionStatus::Reconnecting
                    : VoiceRealtimeSessionStatus::Connecting,
                'lease_owner' => $owner,
                'lease_expires_at' => $now->copy()->addSeconds($ttl),
                'connect_attempts' => $locked->connect_attempts + 1,
                'reconnect_count' => $reconnectCount,
                'reconnect_not_before_at' => null,
                'failure_category' => null,
                'failure_detail' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    public function renewLease(
        VoiceRealtimeSession|int $session,
        string $leaseOwner,
        ?int $ttlSeconds = null,
    ): bool {
        return VoiceRealtimeSession::query()
            ->whereKey($session instanceof VoiceRealtimeSession ? $session->id : $session)
            ->where('lease_owner', $this->normalizeLeaseOwner($leaseOwner))
            ->whereNotIn('status', [
                VoiceRealtimeSessionStatus::Closed->value,
                VoiceRealtimeSessionStatus::Failed->value,
            ])
            ->update([
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds($ttlSeconds)),
                'last_heartbeat_at' => now(),
                'reconnect_not_before_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function markReady(VoiceRealtimeSession|int $session, string $leaseOwner): VoiceRealtimeSession
    {
        return $this->mutateOwnedLease($session, $leaseOwner, function (VoiceRealtimeSession $locked): void {
            if ($locked->lease_expires_at === null || ! $locked->lease_expires_at->isFuture()) {
                throw new VoiceRealtimeLedgerException('The realtime sideband lease expired before connection readiness.');
            }
            $locked->forceFill([
                'status' => VoiceRealtimeSessionStatus::Ready,
                'sideband_connected_at' => now(),
                'last_heartbeat_at' => now(),
                'failure_category' => null,
                'failure_detail' => null,
            ])->save();
        });
    }

    public function markDisconnected(
        VoiceRealtimeSession|int $session,
        string $leaseOwner,
        string $category,
        ?string $detail = null,
    ): VoiceRealtimeSession {
        return DB::transaction(function () use ($session, $leaseOwner, $category, $detail): VoiceRealtimeSession {
            /** @var VoiceRealtimeSession $locked */
            $locked = VoiceRealtimeSession::query()
                ->lockForUpdate()
                ->findOrFail($session instanceof VoiceRealtimeSession ? $session->id : $session);
            $owner = $this->normalizeLeaseOwner($leaseOwner);
            if ($locked->lease_owner === null || ! hash_equals($locked->lease_owner, $owner)) {
                return $locked;
            }

            $attempt = $locked->reconnect_count + 1;
            $failed = $attempt > max(0, (int) config('services.voice_realtime.max_reconnect_attempts', 3));
            $baseDelayMs = max(0, (int) config('services.voice_realtime.reconnect_delay_ms', 250));
            $delayMs = min(5000, $baseDelayMs * (2 ** min(5, max(0, $attempt - 1))));
            $locked->forceFill([
                'status' => $failed
                    ? VoiceRealtimeSessionStatus::Failed
                    : VoiceRealtimeSessionStatus::Reconnecting,
                'reconnect_count' => $attempt,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'reconnect_not_before_at' => $failed ? null : now()->addMilliseconds($delayMs),
                'closed_at' => $failed ? now() : null,
                'failure_category' => mb_substr(trim($category), 0, 100),
                'failure_detail' => $detail === null ? null : $this->privacy->sanitizeTranscript($detail),
            ])->save();

            return $locked->refresh();
        });
    }

    public function releaseForRestart(VoiceRealtimeSession|int $session, string $leaseOwner): VoiceRealtimeSession
    {
        return $this->mutateOwnedLease($session, $leaseOwner, function (VoiceRealtimeSession $locked): void {
            $locked->forceFill([
                'status' => VoiceRealtimeSessionStatus::Reconnecting,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'reconnect_not_before_at' => null,
            ])->save();
        });
    }

    public function markClosed(VoiceRealtimeSession|int $session, ?string $leaseOwner = null): VoiceRealtimeSession
    {
        return DB::transaction(function () use ($session, $leaseOwner): VoiceRealtimeSession {
            /** @var VoiceRealtimeSession $locked */
            $locked = VoiceRealtimeSession::query()
                ->lockForUpdate()
                ->findOrFail($session instanceof VoiceRealtimeSession ? $session->id : $session);
            if ($leaseOwner !== null
                && $locked->lease_owner !== null
                && ! hash_equals($locked->lease_owner, $this->normalizeLeaseOwner($leaseOwner))) {
                return $locked;
            }

            $locked->forceFill([
                'status' => VoiceRealtimeSessionStatus::Closed,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'reconnect_not_before_at' => null,
                'closed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    /** @return Collection<int, VoiceRealtimeSession> */
    public function claimableSessions(?int $limit = null): Collection
    {
        return VoiceRealtimeSession::query()
            ->whereNotNull('provider_call_id')
            ->whereIn('status', [
                VoiceRealtimeSessionStatus::Pending->value,
                VoiceRealtimeSessionStatus::Connecting->value,
                VoiceRealtimeSessionStatus::Ready->value,
                VoiceRealtimeSessionStatus::Reconnecting->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('reconnect_not_before_at')
                    ->orWhere('reconnect_not_before_at', '<=', now()->format('Y-m-d H:i:s.u'));
            })
            ->where(function ($query): void {
                $query->whereNull('lease_owner')
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now()->format('Y-m-d H:i:s.u'));
            })
            ->orderBy('id')
            ->limit(max(1, $limit ?? (int) config('services.voice_realtime.session_batch', 25)))
            ->get();
    }

    /** @return Collection<int, VoiceRealtimeSession> */
    public function failuresAwaitingReconciliation(?int $limit = null): Collection
    {
        return VoiceRealtimeSession::query()
            ->where('status', VoiceRealtimeSessionStatus::Failed->value)
            ->whereNull('metadata->failure_reconciled_at')
            ->orderBy('id')
            ->limit(max(1, $limit ?? (int) config('services.voice_realtime.session_batch', 25)))
            ->get();
    }

    public function markFailureReconciled(VoiceRealtimeSession|int $session): VoiceRealtimeSession
    {
        return DB::transaction(function () use ($session): VoiceRealtimeSession {
            $locked = VoiceRealtimeSession::query()
                ->lockForUpdate()
                ->findOrFail($session instanceof VoiceRealtimeSession ? $session->id : $session);
            if ($locked->status !== VoiceRealtimeSessionStatus::Failed) {
                return $locked;
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if (! filled($metadata['failure_reconciled_at'] ?? null)) {
                $metadata['failure_reconciled_at'] = now()->toIso8601String();
                $locked->forceFill(['metadata' => $metadata])->save();
            }

            return $locked->refresh();
        });
    }

    private function mutateOwnedLease(
        VoiceRealtimeSession|int $session,
        string $leaseOwner,
        callable $mutation,
    ): VoiceRealtimeSession {
        return DB::transaction(function () use ($session, $leaseOwner, $mutation): VoiceRealtimeSession {
            /** @var VoiceRealtimeSession $locked */
            $locked = VoiceRealtimeSession::query()
                ->lockForUpdate()
                ->findOrFail($session instanceof VoiceRealtimeSession ? $session->id : $session);
            $owner = $this->normalizeLeaseOwner($leaseOwner);
            if ($locked->lease_owner === null || ! hash_equals($locked->lease_owner, $owner)) {
                throw new VoiceRealtimeLedgerException('The realtime sideband lease is not owned by this process.');
            }

            $mutation($locked);

            return $locked->refresh();
        });
    }

    private function assertConversationOwnership(User $user, ConversationSession $conversation): void
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new VoiceRealtimeLedgerException('The conversation does not belong to this user.');
        }
    }

    private function normalizeLeaseOwner(string $leaseOwner): string
    {
        $owner = trim($leaseOwner);
        if ($owner === '' || mb_strlen($owner) > 191) {
            throw new VoiceRealtimeLedgerException('The realtime sideband lease owner is invalid.');
        }

        return $owner;
    }

    private function leaseSeconds(?int $ttlSeconds): int
    {
        return max(2, $ttlSeconds ?? (int) config('services.voice_realtime.lease_seconds', 15));
    }
}
