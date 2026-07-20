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
        private readonly BeanActivityLogger $activity,
        private readonly HermesAgentRuntimeService $hermesRuntime,
        private readonly HermesUserHomeService $hermesHomes,
    ) {}

    public function createSession(User $user, ?int $workspaceId = null, ?string $clientTimezone = null): BeanSession
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $workspaceId);
        $timezone = $this->timeContext->normalizeTimezone($clientTimezone);
        $session = BeanSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Bean chat',
            'status' => 'active',
            'metadata' => array_filter([
                'privacy_mode' => true,
                'wake_phrase' => 'Hey Bean',
                'client_timezone' => $timezone,
                'timezone_source' => $timezone !== null ? 'browser' : 'app_default',
                'runtime_driver' => 'hermes',
            ]),
        ]);

        $this->hermesHomes->ensureForSession($session);

        return $session->refresh();
    }

    public function handleMessage(User $user, string $content, ?int $sessionId = null, ?int $workspaceId = null, ?string $clientTimezone = null, ?string $source = null): array
    {
        $session = $sessionId
            ? BeanSession::query()->where('user_id', $user->id)->findOrFail($sessionId)
            : $this->createSession($user, $workspaceId, $clientTimezone);

        if ($clientTimezone !== null && $sessionId) {
            $timezone = $this->timeContext->normalizeTimezone($clientTimezone);
            if ($timezone !== null) {
                $metadata = is_array($session->metadata) ? $session->metadata : [];
                $session->forceFill(['metadata' => [...$metadata, 'client_timezone' => $timezone, 'timezone_source' => 'browser']])->save();
                $session = $session->refresh();
            }
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

        if ($source === 'elevenlabs_agent' && ($fastPath = $this->voiceFastPathTaskList($user, $content, $session, $clientTimezone)) !== null) {
            return $fastPath;
        }

        return $this->hermesRuntime->handleMessage($user, $content, $session, $clientTimezone, $source);
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

    private function voiceFastPathTaskList(User $user, string $content, BeanSession $session, ?string $clientTimezone): ?array
    {
        $timeLabel = $this->voiceTaskListTimeLabel($content);
        if ($timeLabel === null) return null;

        $timeContext = $this->timeContext->forSession($session->refresh());
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'voice_fast_path',
            'input' => $content,
            'metadata' => array_filter([
                'runtime_driver' => 'voice_fast_path',
                'source' => 'elevenlabs_agent',
                'client_timezone' => $timeContext['timezone'],
                'time_context' => $timeContext,
            ]),
            'started_at' => now(),
        ]);

        BeanMessage::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $content,
        ]);
        $this->activity->log($session, $run, 'user_message', $content, ['runtime' => 'voice_fast_path']);
        $this->activity->log($session, $run, 'status', 'Checking tasks', ['mode' => 'working', 'runtime' => 'voice_fast_path']);

        $result = $this->executor->execute($session, $run, 'task.list', [
            'time_label' => $timeLabel,
            'sort' => [['field' => 'due_at', 'direction' => 'asc']],
        ]);
        $assistantText = $this->voiceTaskListAnswer($result, $timeLabel);

        BeanMessage::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $assistantText,
            'metadata' => ['runtime_driver' => 'voice_fast_path', 'source_action' => 'task.list'],
        ]);
        $this->activity->log($session, $run, 'assistant_message', $assistantText, ['runtime' => 'voice_fast_path']);
        $this->activity->log($session, $run, 'status', 'Done', ['mode' => 'wake_listening', 'runtime' => 'voice_fast_path']);
        $run->update([
            'status' => ($result['ok'] ?? false) ? 'completed' : 'failed',
            'model' => 'voice-fast-path',
            'output' => $assistantText,
            'metadata' => [...(is_array($run->metadata) ? $run->metadata : []), 'tool_calls_count' => $run->toolCalls()->count()],
            'completed_at' => now(),
        ]);

        return $this->withConversationState($session->refresh(), ['run' => $run->refresh()]);
    }

    private function voiceTaskListTimeLabel(string $content): ?string
    {
        $normalized = mb_strtolower(trim(preg_replace('/[^\pL\pN\s-]/u', ' ', $content) ?: $content));
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);
        if (! preg_match('/\b(task|tasks|todo|todos|to-do|to-dos)\b/u', $normalized)) return null;
        if (! preg_match('/\b(have|what|whats|what s|list|show|anything|on)\b/u', $normalized)) return null;
        if (preg_match('/\btomorrow\b/u', $normalized)) return 'tomorrow';
        if (preg_match('/\btoday\b/u', $normalized)) return 'today';
        return null;
    }

    private function voiceTaskListAnswer(array $result, string $timeLabel): string
    {
        if (($result['ok'] ?? false) !== true) {
            return 'I could not check your to-do list right now.';
        }
        $items = array_values(is_array($result['items'] ?? null) ? $result['items'] : []);
        $count = (int) ($result['total_count'] ?? count($items));
        if ($count === 0) {
            return "You do not have any tasks on your to-do list for {$timeLabel}.";
        }
        $names = collect($items)->take(5)->map(function (array $item): string {
            $title = trim((string) ($item['title'] ?? 'Untitled task'));
            $workspace = trim((string) ($item['workspace_name'] ?? ''));
            return $workspace !== '' ? "\"{$title}\" in {$workspace}" : "\"{$title}\"";
        })->values()->all();
        $prefix = $count === 1 ? "You have one task on your to-do list for {$timeLabel}: " : "You have {$count} tasks on your to-do list for {$timeLabel}: ";
        $text = $prefix.implode('; ', $names);
        if ($count > count($names)) {
            $text .= '; plus '.($count - count($names)).' more';
        }
        return $text.'.';
    }

    private function isAffirmativeConfirmationReply(string $content): bool
    {
        $lower = mb_strtolower(trim(preg_replace('/[^\pL\pN\s]/u', ' ', $content) ?: $content));
        $lower = trim(preg_replace('/\s+/', ' ', $lower) ?: $lower);

        return preg_match('/^(yes|yeah|yep|yup|ok|okay|confirm|confirmed|approve|approved|do it|go ahead|that is right|that\s right)$/u', $lower) === 1;
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
