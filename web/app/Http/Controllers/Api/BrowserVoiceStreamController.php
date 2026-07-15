<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\BrowserVoiceProjectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrowserVoiceStreamController extends Controller
{
    private const MAX_CURSOR = 9_007_199_254_740_991;

    public function __construct(
        private readonly BrowserVoiceProjectionService $projection,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_CURSOR],
        ]);
        $session = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail((int) $data['session_id']);
        $cursor = max((int) ($data['cursor'] ?? 0), $this->lastEventId($request));
        $streamSeconds = max(0.05, min(
            25.0,
            (float) config('services.voice_realtime.sse_stream_seconds', 20.0),
        ));
        $heartbeatSeconds = max(0.05, min(
            5.0,
            (float) config('services.voice_realtime.sse_heartbeat_seconds', 5.0),
        ));
        $pollSeconds = max(0.02, min(
            0.5,
            (float) config('services.voice_realtime.sse_poll_seconds', 0.1),
        ));

        return response()->stream(function () use (
            $session,
            $cursor,
            $streamSeconds,
            $heartbeatSeconds,
            $pollSeconds,
        ): void {
            $startedAt = microtime(true);
            $nextHeartbeatAt = $startedAt + $heartbeatSeconds;
            $projection = $this->projection->forSession($session, $cursor);
            $cursor = (int) $projection['cursor'];
            $this->sendProjection($projection, $cursor);

            while (! connection_aborted() && microtime(true) - $startedAt < $streamSeconds) {
                if ($this->projection->hasEventsAfter($session, $cursor)) {
                    $projection = $this->projection->forSession($session, $cursor);
                    $nextCursor = (int) $projection['cursor'];
                    if ($nextCursor > $cursor) {
                        $cursor = $nextCursor;
                        $this->sendProjection($projection, $cursor);
                    }
                }

                $now = microtime(true);
                if ($now >= $nextHeartbeatAt) {
                    echo ': heartbeat '.$cursor."\n\n";
                    $this->flush();
                    $nextHeartbeatAt = $now + $heartbeatSeconds;
                }

                $remainingSeconds = $streamSeconds - (microtime(true) - $startedAt);
                if ($remainingSeconds <= 0) {
                    break;
                }
                usleep((int) round(min($pollSeconds, $remainingSeconds) * 1_000_000));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function lastEventId(Request $request): int
    {
        $value = trim((string) $request->header('Last-Event-ID', ''));
        if ($value === '') {
            return 0;
        }
        if (preg_match('/\A\d{1,16}\z/', $value) !== 1
            || (int) $value > self::MAX_CURSOR) {
            throw ValidationException::withMessages([
                'Last-Event-ID' => 'Last-Event-ID must be a non-negative integer voice-event cursor.',
            ]);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $projection */
    private function sendProjection(array $projection, int $cursor): void
    {
        try {
            $data = json_encode(
                $projection,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return;
        }

        echo 'id: '.$cursor."\n";
        echo "event: voice-state\n";
        echo 'data: '.$data."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
