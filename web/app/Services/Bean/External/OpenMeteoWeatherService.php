<?php

namespace App\Services\Bean\External;

use App\Models\BeanRun;
use Illuminate\Support\Facades\Http;

class OpenMeteoWeatherService
{
    public function forecast(array $arguments, BeanRun $run): array
    {
        $units = $this->units($arguments);
        $timeContext = is_array(data_get($run->metadata, 'time_context')) ? data_get($run->metadata, 'time_context') : [];
        $location = $this->resolveLocation($arguments, $run);
        if (($location['ok'] ?? false) !== true) {
            return $location;
        }

        $forecastDays = max(1, min(7, (int) ($arguments['forecast_days'] ?? $arguments['days'] ?? 3)));
        $timezone = trim((string) ($location['timezone'] ?? data_get($timeContext, 'timezone', '')));
        $timezone = $timezone !== '' ? $timezone : 'auto';

        $query = [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'current' => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'apparent_temperature',
                'precipitation',
                'weather_code',
                'wind_speed_10m',
            ]),
            'daily' => implode(',', [
                'weather_code',
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_probability_max',
                'precipitation_sum',
            ]),
            'timezone' => $timezone,
            'temperature_unit' => $units['temperature_unit'],
            'wind_speed_unit' => $units['wind_speed_unit'],
            'precipitation_unit' => $units['precipitation_unit'],
            'forecast_days' => $forecastDays,
        ];

        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(6)
            ->get('https://api.open-meteo.com/v1/forecast', $query);

        if (! $response->successful()) {
            return ['ok' => false, 'provider' => 'open-meteo', 'error' => 'Weather provider did not return a usable forecast.'];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return ['ok' => false, 'provider' => 'open-meteo', 'error' => 'Weather provider returned an unreadable forecast.'];
        }

        return [
            'ok' => true,
            'provider' => 'open-meteo',
            'retrieved_at' => now()->toIso8601String(),
            'location' => [
                'name' => $location['name'] ?? null,
                'source' => $location['source'],
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'timezone' => (string) ($payload['timezone'] ?? $timezone),
                'accuracy_meters' => $location['accuracy'] ?? null,
            ],
            'units' => [
                'temperature' => data_get($payload, 'current_units.temperature_2m', $units['temperature_label']),
                'wind_speed' => data_get($payload, 'current_units.wind_speed_10m', $units['wind_label']),
                'precipitation' => data_get($payload, 'daily_units.precipitation_sum', $units['precipitation_label']),
            ],
            'current' => $this->currentWeather(is_array($payload['current'] ?? null) ? $payload['current'] : []),
            'daily_forecast' => $this->dailyForecast(is_array($payload['daily'] ?? null) ? $payload['daily'] : []),
            'source_urls' => [
                'forecast' => 'https://api.open-meteo.com/v1/forecast',
                'attribution' => 'https://open-meteo.com/',
            ],
        ];
    }

    private function resolveLocation(array $arguments, BeanRun $run): array
    {
        $argumentCoordinates = $this->coordinateCandidate($arguments);
        if ($argumentCoordinates !== null) {
            return $this->coordinateLocation($argumentCoordinates, $run);
        }

        $locationName = $this->locationNameFromArguments($arguments)
            ?: $this->extractNamedLocationFromText((string) $run->input);
        if ($locationName !== '') {
            return $this->geocodeLocation($locationName);
        }

        $candidate = $this->coordinateCandidate(data_get($run->metadata, 'client_location'))
            ?? $this->coordinateCandidate(data_get($run->session?->metadata ?? [], 'client_location'));

        if ($candidate !== null) {
            return $this->coordinateLocation($candidate, $run);
        }

        return [
            'ok' => false,
            'provider' => 'open-meteo',
            'error' => 'I need a location for weather. Enable browser location or tell me a city/place.',
            'location' => [
                'source' => 'missing',
                'timezone' => data_get($run->metadata, 'time_context.timezone'),
            ],
        ];
    }

    private function coordinateLocation(array $candidate, BeanRun $run): array
    {
        return [
            'ok' => true,
            'source' => $candidate['source'] ?? 'browser',
            'latitude' => $candidate['latitude'],
            'longitude' => $candidate['longitude'],
            'accuracy' => $candidate['accuracy'] ?? null,
            'timezone' => data_get($run->metadata, 'time_context.timezone'),
        ];
    }

    private function locationNameFromArguments(array $arguments): string
    {
        return trim((string) ($arguments['location'] ?? $arguments['place'] ?? $arguments['city'] ?? $arguments['query'] ?? ''));
    }

    private function extractNamedLocationFromText(string $text): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
        if ($normalized === '') {
            return '';
        }

        $patterns = [
            '/\b(?:weather|forecast|temperature|temp|rain|snow|sleet|hail|storm|storms|wind|windy|humidity|degrees|outside)\b.*?\b(?:in|for|near|around|at)\s+(.+?)\s*(?:\?|!|\.)?$/iu',
            '/\b(?:in|for|near|around|at)\s+(.+?)\s*(?:\?|!|\.)?$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) !== 1) {
                continue;
            }

            $location = $this->cleanExtractedLocation((string) ($matches[1] ?? ''));
            if ($location !== '') {
                return $location;
            }
        }

        return '';
    }

    private function cleanExtractedLocation(string $location): string
    {
        $location = trim(preg_replace('/\s+/u', ' ', $location) ?: $location, " \t\n\r\0\x0B,.;:!?\"'“”‘’");
        $location = preg_replace('/\s+\b(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening|this week|this weekend)\b.*$/iu', '', $location) ?: $location;
        $location = trim($location, " \t\n\r\0\x0B,.;:!?\"'“”‘’");

        if (preg_match('/^(?:here|outside|near me|my location|current location|me|home)$/iu', $location) === 1) {
            return '';
        }

        return mb_substr($location, 0, 120);
    }

    private function coordinateCandidate(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $latitude = $value['latitude'] ?? $value['lat'] ?? data_get($value, 'coords.latitude');
        $longitude = $value['longitude'] ?? $value['lng'] ?? $value['lon'] ?? data_get($value, 'coords.longitude');
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = round((float) $latitude, 6);
        $longitude = round((float) $longitude, 6);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        $accuracy = $value['accuracy'] ?? data_get($value, 'coords.accuracy');

        return array_filter([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => is_numeric($accuracy) ? round((float) $accuracy, 2) : null,
            'source' => trim((string) ($value['source'] ?? 'browser')) ?: 'browser',
        ], static fn ($item): bool => $item !== null && $item !== '');
    }

    private function geocodeLocation(string $location): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(6)
            ->get('https://geocoding-api.open-meteo.com/v1/search', [
                'name' => $location,
                'count' => 1,
                'language' => 'en',
                'format' => 'json',
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'provider' => 'open-meteo', 'error' => 'I could not geocode that weather location.'];
        }

        $place = data_get($response->json(), 'results.0');
        if (! is_array($place) || ! is_numeric($place['latitude'] ?? null) || ! is_numeric($place['longitude'] ?? null)) {
            return ['ok' => false, 'provider' => 'open-meteo', 'error' => 'I could not find that weather location.'];
        }

        return [
            'ok' => true,
            'source' => 'geocoded',
            'name' => $this->placeName($place),
            'latitude' => round((float) $place['latitude'], 6),
            'longitude' => round((float) $place['longitude'], 6),
            'timezone' => trim((string) ($place['timezone'] ?? '')) ?: null,
        ];
    }

    private function placeName(array $place): string
    {
        return collect([$place['name'] ?? null, $place['admin1'] ?? null, $place['country'] ?? null])
            ->map(fn ($part): string => trim((string) $part))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function units(array $arguments): array
    {
        $units = strtolower(trim((string) ($arguments['units'] ?? 'imperial')));
        if (in_array($units, ['metric', 'si', 'celsius'], true)) {
            return [
                'temperature_unit' => 'celsius',
                'temperature_label' => '°C',
                'wind_speed_unit' => 'kmh',
                'wind_label' => 'km/h',
                'precipitation_unit' => 'mm',
                'precipitation_label' => 'mm',
            ];
        }

        return [
            'temperature_unit' => 'fahrenheit',
            'temperature_label' => '°F',
            'wind_speed_unit' => 'mph',
            'wind_label' => 'mp/h',
            'precipitation_unit' => 'inch',
            'precipitation_label' => 'inch',
        ];
    }

    private function currentWeather(array $current): array
    {
        $code = is_numeric($current['weather_code'] ?? null) ? (int) $current['weather_code'] : null;

        return [
            'time' => $current['time'] ?? null,
            'conditions' => $this->weatherCodeLabel($code),
            'weather_code' => $code,
            'temperature' => $this->numberOrNull($current['temperature_2m'] ?? null),
            'feels_like' => $this->numberOrNull($current['apparent_temperature'] ?? null),
            'humidity_percent' => $this->numberOrNull($current['relative_humidity_2m'] ?? null),
            'precipitation' => $this->numberOrNull($current['precipitation'] ?? null),
            'wind_speed' => $this->numberOrNull($current['wind_speed_10m'] ?? null),
        ];
    }

    private function dailyForecast(array $daily): array
    {
        $dates = is_array($daily['time'] ?? null) ? $daily['time'] : [];

        return collect($dates)->map(function ($date, int $index) use ($daily): array {
            $code = is_numeric(data_get($daily, "weather_code.{$index}")) ? (int) data_get($daily, "weather_code.{$index}") : null;

            return [
                'date' => $date,
                'conditions' => $this->weatherCodeLabel($code),
                'weather_code' => $code,
                'temperature_max' => $this->numberOrNull(data_get($daily, "temperature_2m_max.{$index}")),
                'temperature_min' => $this->numberOrNull(data_get($daily, "temperature_2m_min.{$index}")),
                'precipitation_probability_max' => $this->numberOrNull(data_get($daily, "precipitation_probability_max.{$index}")),
                'precipitation_sum' => $this->numberOrNull(data_get($daily, "precipitation_sum.{$index}")),
            ];
        })->values()->all();
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function weatherCodeLabel(?int $code): ?string
    {
        return match ($code) {
            0 => 'clear sky',
            1 => 'mainly clear',
            2 => 'partly cloudy',
            3 => 'overcast',
            45 => 'fog',
            48 => 'depositing rime fog',
            51 => 'drizzle: light',
            53 => 'drizzle: moderate',
            55 => 'drizzle: dense',
            56 => 'freezing drizzle: light',
            57 => 'freezing drizzle: dense',
            61 => 'rain: slight',
            63 => 'rain: moderate',
            65 => 'rain: heavy',
            66 => 'freezing rain: light',
            67 => 'freezing rain: heavy',
            71 => 'snow fall: slight',
            73 => 'snow fall: moderate',
            75 => 'snow fall: heavy',
            77 => 'snow grains',
            80 => 'rain showers: slight',
            81 => 'rain showers: moderate',
            82 => 'rain showers: violent',
            85 => 'snow showers: slight',
            86 => 'snow showers: heavy',
            95 => 'thunderstorm',
            96 => 'thunderstorm with slight hail',
            99 => 'thunderstorm with heavy hail',
            default => $code === null ? null : 'unknown',
        };
    }
}
