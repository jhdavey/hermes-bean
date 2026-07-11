<?php

namespace App\Services;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;

class FastWeatherReadService
{
    public function __construct(private readonly OpenMeteoWeatherService $weather) {}

    public function resolve(ConversationSession $session, string $content, array $metadata = []): ?string
    {
        if (! (bool) config('services.hermes_runtime.weather_lookup_enabled', true)) {
            return null;
        }

        $timezone = trim((string) ($metadata['client_timezone'] ?? data_get($session->metadata, 'client_timezone', '')));
        $query = $content;
        $currentLocation = $this->weather->locationForQuery($query);
        if ($currentLocation === '' || preg_match('/^(?:later|earlier|before|after|there|that|it)$/iu', $currentLocation)) {
            $priorQuery = ConversationMessage::query()
                ->where('conversation_session_id', $session->id)
                ->where('role', 'user')
                ->where('content', '!=', $content)
                ->latest('id')
                ->limit(12)
                ->pluck('content')
                ->first(function (string $prior): bool {
                    $location = $this->weather->locationForQuery($prior);

                    return $location !== ''
                        && ! preg_match('/^(?:later|earlier|before|after|there|that|it)$/iu', $location);
                });
            if (filled($priorQuery)) {
                $query = (string) $priorQuery;
            }
        }

        $result = $this->weather->forecastForQuery($query, $timezone, [
            'source' => 'deterministic_voice_weather',
            'session_id' => $session->id,
            'workspace_id' => $session->workspace_id,
            'timezone' => $timezone,
            'query' => $query,
        ]);
        if ($result === null) {
            return null;
        }
        if (($result['ok'] ?? false) === true && filled($result['text'] ?? null)) {
            return trim((string) $result['text']);
        }

        return trim((string) ($result['message'] ?? 'I couldn’t retrieve the weather quickly enough. Please try again.'));
    }
}
