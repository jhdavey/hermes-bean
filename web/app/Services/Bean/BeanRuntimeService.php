<?php

namespace App\Services\Bean;

use App\Models\BeanConfirmationRequest;
use App\Models\BeanMessage;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\DB;

class BeanRuntimeService
{
    public function __construct(
        private readonly BeanActionExecutor $executor,
        private readonly BeanTimeContext $timeContext,
        private readonly HermesAgentRuntimeService $hermesRuntime,
        private readonly HermesUserHomeService $hermesHomes,
    ) {}

    public function createSession(User $user, ?int $workspaceId = null, ?string $clientTimezone = null, ?array $clientLocation = null): BeanSession
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $workspaceId);
        $timezone = $this->timeContext->rememberUserTimezoneIfMissing($user, $clientTimezone)
            ?: $this->timeContext->normalizeTimezone($clientTimezone);
        $timeContext = $this->timeContext->forUser($user->refresh(), $timezone);
        $session = BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Bean chat',
            'status' => 'active',
            'metadata' => array_filter([
                'privacy_mode' => true,
                'wake_phrase' => 'Hey Bean',
                'client_timezone' => $timeContext['timezone'],
                'timezone_source' => $timeContext['timezone_source'],
                'client_location' => $this->normalizeClientLocation($clientLocation),
                'runtime_driver' => 'hermes',
            ]),
        ]);

        $this->hermesHomes->ensureForSession($session);

        return $session->refresh();
    }

    public function handleMessage(User $user, string $content, ?int $sessionId = null, ?int $workspaceId = null, ?string $clientTimezone = null, ?string $source = null, ?array $clientLocation = null): array
    {
        $session = $sessionId
            ? BeanSession::query()->where('user_id', $user->id)->findOrFail($sessionId)
            : $this->createSession($user, $workspaceId, $clientTimezone, $clientLocation);

        if ($clientTimezone !== null && $sessionId) {
            $timezone = $this->timeContext->rememberUserTimezoneIfMissing($user, $clientTimezone)
                ?: $this->timeContext->normalizeTimezone($clientTimezone);
            if ($timezone !== null) {
                $timeContext = $this->timeContext->forUser($user->refresh(), $timezone);
                $metadata = is_array($session->metadata) ? $session->metadata : [];
                $session->forceFill(['metadata' => [...$metadata, 'client_timezone' => $timeContext['timezone'], 'timezone_source' => $timeContext['timezone_source']]])->save();
                $session = $session->refresh();
            }
        }

        if ($clientLocation !== null) {
            $this->rememberClientLocation($session, $clientLocation);
            $session = $session->refresh();
        }

        if ($this->isAffirmativeConfirmationReply($content) && $sessionId) {
            $confirmation = BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if ($confirmation) {
                return $this->withConversationState($session, $this->approveConfirmation($user, $confirmation->id));
            }
        }

        return $this->hermesRuntime->handleMessage($user, $content, $session, $clientTimezone, $source);
    }

    public function rememberClientLocation(BeanSession $session, ?array $clientLocation): void
    {
        $normalized = $this->normalizeClientLocation($clientLocation);
        if ($normalized === null) {
            return;
        }

        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $session->forceFill(['metadata' => [...$metadata, 'client_location' => $normalized]])->save();
    }

    public function approveConfirmation(User $user, int $confirmationId): array
    {
        return DB::transaction(function () use ($user, $confirmationId): array {
            $confirmation = BeanConfirmationRequest::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($confirmationId);
            $session = $confirmation->session;
            $sessionTimeContext = $this->timeContext->forSession($session);
            $run = $confirmation->run ?: BeanRun::create([
                'bean_session_id' => $session->id,
                'user_id' => $user->id,
                'workspace_id' => $session->workspace_id,
                'status' => 'running',
                'mode' => 'confirmation',
                'metadata' => [
                    'runtime_driver' => 'hermes',
                    'client_timezone' => $sessionTimeContext['timezone'],
                    'time_context' => $sessionTimeContext,
                ],
                'started_at' => now(),
            ]);

            $result = $this->executor->execute($session, $run, $confirmation->action, $confirmation->arguments ?? [], true);
            $ok = ($result['ok'] ?? false) === true;
            $confirmation->update(['status' => $ok ? 'approved' : 'failed', 'approved_at' => $ok ? now() : null]);
            $text = $ok ? 'Done — I completed the confirmed action.' : 'I could not complete that confirmed action.';

            BeanMessage::create([
                'bean_session_id' => $session->id,
                'bean_run_id' => $run->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $text,
                'metadata' => ['result' => $result, 'runtime_driver' => 'hermes'],
            ]);
            $runMetadata = is_array($run->metadata) ? $run->metadata : [];
            $run->update([
                'status' => $ok ? 'completed' : 'failed',
                'output' => $text,
                'metadata' => [...$runMetadata, 'result' => $result],
                'completed_at' => now(),
            ]);

            return ['confirmation' => $confirmation->refresh(), 'run' => $run->refresh(), 'result' => $result];
        });
    }

    private function isAffirmativeConfirmationReply(string $content): bool
    {
        $lower = mb_strtolower(trim(preg_replace('/[^\pL\pN\s]/u', ' ', $content) ?: $content));
        $lower = trim(preg_replace('/\s+/', ' ', $lower) ?: $lower);

        return preg_match('/^(yes|yeah|yep|yup|ok|okay|confirm|confirmed|approve|approved|do it|go ahead|that is right|that\s right)$/u', $lower) === 1;
    }

    private function normalizeClientLocation(?array $clientLocation): ?array
    {
        if (! is_array($clientLocation)) {
            return null;
        }

        $latitude = $clientLocation['latitude'] ?? $clientLocation['lat'] ?? null;
        $longitude = $clientLocation['longitude'] ?? $clientLocation['lng'] ?? $clientLocation['lon'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = round((float) $latitude, 6);
        $longitude = round((float) $longitude, 6);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        $accuracy = $clientLocation['accuracy'] ?? null;

        return array_filter([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => is_numeric($accuracy) ? round((float) $accuracy, 2) : null,
            'source' => trim((string) ($clientLocation['source'] ?? 'browser')) ?: 'browser',
            'captured_at' => trim((string) ($clientLocation['captured_at'] ?? '')) ?: now()->toIso8601String(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function withConversationState(BeanSession $session, array $payload): array
    {
        return [
            ...$payload,
            'session' => $session->refresh(),
            'messages' => $session->messages()->latest('id')->limit(20)->get()->reverse()->values(),
            'activity' => $session->activityEvents()->latest('id')->limit(50)->get()->reverse()->values(),
            'confirmations' => BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('status', 'pending')
                ->latest('id')
                ->get(),
        ];
    }
}
