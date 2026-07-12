<?php

namespace App\Services;

use App\Exceptions\BrowserVoiceHandlerException;
use App\Models\AgentProfile;
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
            $priorQuery = ($metadata['allow_prior_context'] ?? false) === true
                ? trim((string) ($metadata['prior_transcript'] ?? ''))
                : '';
            $priorLocation = $this->weather->locationForQuery($priorQuery);
            if ($priorLocation === ''
                || preg_match('/^(?:later|earlier|before|after|there|that|it)$/iu', $priorLocation)) {
                $priorQuery = null;
            }
            if (filled($priorQuery)) {
                $query = trim((string) $priorQuery).' '.trim($content);
            } else {
                $profile = AgentProfile::query()
                    ->where('user_id', $session->user_id)
                    ->where('workspace_id', $session->workspace_id)
                    ->first();
                $homeLocation = trim((string) (
                    $metadata['location_context']['label']
                    ?? data_get($profile?->settings, 'weather.location')
                    ?? data_get($profile?->settings, 'default_weather_location')
                    ?? data_get($profile?->settings, 'home_location')
                    ?? ''
                ));
                if ($homeLocation !== '') {
                    $query = trim($content).' in '.$homeLocation;
                }
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
            throw new BrowserVoiceHandlerException(
                'weather_request_unsupported',
                'The dedicated weather handler could not interpret the admitted weather request.',
                'I couldn’t interpret that weather request. Would you like to try it again with a location and time?',
            );
        }
        if (($result['ok'] ?? false) === true && filled($result['text'] ?? null)) {
            return trim((string) $result['text']);
        }

        $errorCode = trim((string) ($result['error_code'] ?? 'weather_provider_failure'));
        $message = trim((string) ($result['message'] ?? 'I couldn’t retrieve the weather quickly enough.'));
        throw new BrowserVoiceHandlerException(
            $errorCode,
            $message,
            rtrim($message, '.').' Would you like me to try again?',
            in_array($errorCode, [
                'weather_lookup_timeout',
                'weather_lookup_connect_timeout',
                'weather_forecast_failed',
                'weather_hourly_forecast_failed',
                'weather_daily_forecast_failed',
                'weather_geocode_failed',
            ], true),
        );
    }
}
