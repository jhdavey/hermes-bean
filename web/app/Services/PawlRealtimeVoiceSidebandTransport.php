<?php

namespace App\Services;

use App\Contracts\RealtimeVoiceSidebandTransport;
use App\Exceptions\VoiceRealtimeLedgerException;
use App\Models\VoiceRealtimeSession;
use Closure;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Socket\Connector as SocketConnector;
use RuntimeException;
use Throwable;

class PawlRealtimeVoiceSidebandTransport implements RealtimeVoiceSidebandTransport
{
    public function connect(
        VoiceRealtimeSession $session,
        Closure $onMessage,
        Closure $onClose,
        Closure $onError,
    ): PromiseInterface {
        if ($session->provider_call_id === null) {
            throw new VoiceRealtimeLedgerException('The realtime session has no provider call identifier.');
        }

        $apiKey = (string) (config('services.openai.server_api_key')
            ?: config('services.hermes_runtime.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('The server OpenAI API key is not configured.');
        }

        $base = rtrim((string) config(
            'services.openai.realtime_sideband_url',
            'wss://api.openai.com/v1/realtime',
        ), '?&');
        $separator = str_contains($base, '?') ? '&' : '?';
        $url = $base.$separator.http_build_query(['call_id' => $session->provider_call_id]);
        $loop = Loop::get();
        $connector = new Connector($loop, new SocketConnector([
            'timeout' => max(1, (float) config('services.voice_realtime.connect_timeout_seconds', 10)),
        ], $loop));

        return $connector($url, [], [
            'Authorization' => 'Bearer '.$apiKey,
            'OpenAI-Safety-Identifier' => hash_hmac(
                'sha256',
                (string) $session->user_id,
                (string) config('app.key'),
            ),
        ])->then(function (WebSocket $socket) use ($onMessage, $onClose, $onError) {
            $socket->on('message', static function ($message) use ($onMessage): void {
                $onMessage((string) $message);
            });
            $socket->on('close', static function ($code, $reason) use ($onClose): void {
                $onClose(is_int($code) ? $code : 1006, is_string($reason) ? $reason : '');
            });
            $socket->on('error', static function ($error) use ($onError): void {
                $onError($error instanceof Throwable
                    ? $error
                    : new RuntimeException('The realtime sideband transport failed.'));
            });

            return new PawlRealtimeVoiceSidebandConnection($socket);
        });
    }
}
