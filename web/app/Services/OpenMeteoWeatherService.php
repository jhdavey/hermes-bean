<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoWeatherService
{
    /**
     * Execute already interpreted weather arguments. Query prose is retained
     * only for diagnostics and is never parsed for place or time.
     */
    public function weatherForStructuredIntent(
        array $arguments,
        string $timezone = '',
        array $logContext = [],
        ?Carbon $hardDeadlineAt = null,
    ): ?array {
        if (! $this->structuredWeatherIntentPresent($arguments)) {
            return null;
        }

        $unitSystem = $this->canonicalWeatherUnitSystem($arguments, true);
        if ($unitSystem === null) {
            return $this->openMeteoFailureResult(
                'weather_units_invalid',
                ($arguments['kind'] ?? null) === 'forecast' ? 'weather_forecast' : 'weather_current',
            );
        }
        $logContext = [
            ...$logContext,
            'units' => $unitSystem,
        ];

        $strictTemporal = $this->strictStructuredWeatherTemporal($arguments);
        if ($strictTemporal['error_code'] !== null) {
            return $this->openMeteoFailureResult(
                $strictTemporal['error_code'],
                $strictTemporal['kind'] === 'forecast' ? 'weather_forecast' : 'weather_current',
                details: array_filter([
                    'unsupported_fields' => $strictTemporal['unsupported_fields'] ?? [],
                ]),
            );
        }

        $locationQuery = $this->structuredWeatherLocation((string) ($arguments['location'] ?? ''));
        if ($locationQuery === '' && $this->trustedCoordinates($logContext) !== null) {
            $locationLabel = (string) ($logContext['location_label'] ?? '');
            $locationQuery = $this->structuredWeatherLocation($locationLabel) ?: 'your location';
        }
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather',
                'error_code' => 'weather_location_missing',
                'required_fields' => ['location'],
            ];
        }

        $targetTime = $strictTemporal['time'];
        $targetDate = $strictTemporal['date'];
        if ($targetTime !== null) {
            return $this->hourlyForecast($locationQuery, (string) $targetDate, $targetTime, [
                ...$logContext,
                'query' => $arguments['query'] ?? null,
                'structured_weather_intent' => true,
                'strict_structured_weather' => true,
                'timezone' => $timezone,
            ], $hardDeadlineAt);
        }

        if ($strictTemporal['kind'] === 'weather') {
            return $this->currentWeather($locationQuery, [
                ...$logContext,
                'query' => $arguments['query'] ?? null,
                'structured_weather_intent' => true,
                'strict_structured_weather' => true,
            ], $hardDeadlineAt);
        }

        return $this->dailyForecast($locationQuery, (string) $targetDate, [
            ...$logContext,
            'query' => $arguments['query'] ?? null,
            'structured_weather_intent' => true,
            'strict_structured_weather' => true,
            'timezone' => $timezone,
        ], $hardDeadlineAt);
    }

    private function currentWeather(
        string $locationQuery,
        array $logContext = [],
        ?Carbon $hardDeadlineAt = null,
    ): array {
        $units = $this->openMeteoUnits($logContext);
        $locationQuery = $this->structuredWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_current',
                'error_code' => 'weather_location_missing',
                'required_fields' => ['location'],
            ];
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_current_weather:'.sha1(mb_strtolower(
            $this->weatherLocationCacheIdentity($locationQuery, $logContext),
        ));
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return [
                    ...$cached,
                    'cached' => true,
                ];
            }
        }

        try {
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext, $hardDeadlineAt);
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->openMeteoFailureResult('external_lookup_deadline');
            }
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult(
                    (string) $geocodeResult['error_code'],
                    candidates: (array) ($geocodeResult['candidates'] ?? []),
                    details: $this->geocodeFailureDetails($geocodeResult),
                );
            }

            $place = $geocodeResult['place'] ?? null;
            if (! is_array($place)) {
                return [
                    'ok' => false,
                    'tool' => 'external_lookup',
                    'provider' => 'open_meteo',
                    'kind' => 'weather_current',
                    'error_code' => 'weather_location_not_found',
                    'query' => $logContext['query'] ?? null,
                    'location' => $locationQuery,
                ];
            }

            $timeouts = $this->httpTimeouts($hardDeadlineAt);
            $forecast = Http::acceptJson()
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    'temperature_unit' => $units['temperature_api'],
                    'wind_speed_unit' => $units['wind_api'],
                    'precipitation_unit' => $units['precipitation_api'],
                    'timezone' => 'auto',
                ]);

            if (! $forecast->successful()) {
                Log::warning('Open-Meteo forecast failed.', [
                    ...$logContext,
                    'status' => $forecast->status(),
                    'body' => mb_substr($forecast->body(), 0, 1000),
                    'location_query' => $locationQuery,
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                ]);

                return $this->openMeteoFailureResult(
                    'weather_forecast_failed',
                    details: ['stage' => 'forecast', 'status' => $forecast->status()],
                );
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_forecast_non_json', details: ['stage' => 'forecast_response']);
            }

            $current = data_get($decoded, 'current');
            if (! is_array($current)) {
                return $this->openMeteoFailureResult('weather_current_missing', details: ['stage' => 'forecast_response']);
            }

            $placeName = $this->openMeteoPlaceName($place);
            $text = $this->openMeteoCurrentWeatherText($placeName, $current, $units);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_current_empty', details: ['stage' => 'forecast_response']);
            }

            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_current',
                'query' => $logContext['query'] ?? $locationQuery,
                'location' => $placeName,
                'text' => $text,
                'weather' => [
                    'units' => $units['system'],
                    'time' => data_get($current, 'time'),
                    'temperature_'.$units['temperature_suffix'] => $this->roundedWeatherValue(data_get($current, 'temperature_2m')),
                    'apparent_temperature_'.$units['temperature_suffix'] => $this->roundedWeatherValue(data_get($current, 'apparent_temperature')),
                    'relative_humidity_percent' => $this->roundedWeatherValue(data_get($current, 'relative_humidity_2m')),
                    'precipitation_'.$units['precipitation_suffix'] => $this->roundedWeatherValue(data_get($current, 'precipitation'), 2),
                    'weather_code' => data_get($current, 'weather_code'),
                    'description' => $this->openMeteoWeatherCodeDescription((int) data_get($current, 'weather_code', -1)),
                    'cloud_cover_percent' => $this->roundedWeatherValue(data_get($current, 'cloud_cover')),
                    'wind_speed_'.$units['wind_suffix'] => $this->roundedWeatherValue(data_get($current, 'wind_speed_10m')),
                    'wind_direction_degrees' => $this->roundedWeatherValue(data_get($current, 'wind_direction_10m')),
                    'wind_gusts_'.$units['wind_suffix'] => $this->roundedWeatherValue(data_get($current, 'wind_gusts_10m')),
                ],
                'sources' => [[
                    'title' => 'Open-Meteo Forecast API',
                    'url' => 'https://open-meteo.com/',
                ]],
                'cached' => false,
            ];

            if ($useCache) {
                Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.weather_warm_cache_seconds', 300)));
            }

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Open-Meteo lookup transport failed.', [
                ...$logContext,
                'location_query' => $locationQuery,
                'exception' => $exception->getMessage(),
            ]);

            return $this->openMeteoFailureResult('weather_lookup_timeout', details: ['stage' => 'weather_pipeline']);
        }
    }

    private function hourlyForecast(
        string $locationQuery,
        string $date,
        string $time,
        array $logContext = [],
        ?Carbon $hardDeadlineAt = null,
    ): array {
        $units = $this->openMeteoUnits($logContext);
        $locationQuery = $this->structuredWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_hourly_forecast',
                'error_code' => 'weather_location_missing',
                'required_fields' => ['location'],
            ];
        }

        $date = $this->absoluteWeatherDate($date);
        $time = $this->absoluteWeatherTime($time);
        if ($date === null || $time === null) {
            return $this->openMeteoFailureResult('weather_hourly_datetime_invalid', 'weather_hourly_forecast');
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_hourly_forecast:'.sha1(mb_strtolower(
            $this->weatherLocationCacheIdentity($locationQuery, $logContext).'|'.$date.'|'.$time,
        ));
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return [
                    ...$cached,
                    'cached' => true,
                ];
            }
        }

        try {
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext, $hardDeadlineAt);
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->openMeteoFailureResult('external_lookup_deadline', 'weather_hourly_forecast');
            }
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult(
                    (string) $geocodeResult['error_code'],
                    'weather_hourly_forecast',
                    (array) ($geocodeResult['candidates'] ?? []),
                    $this->geocodeFailureDetails($geocodeResult),
                );
            }

            $place = $geocodeResult['place'] ?? null;
            if (! is_array($place)) {
                return [
                    'ok' => false,
                    'tool' => 'external_lookup',
                    'provider' => 'open_meteo',
                    'kind' => 'weather_hourly_forecast',
                    'error_code' => 'weather_location_not_found',
                    'query' => $logContext['query'] ?? null,
                    'location' => $locationQuery,
                    'date' => $date,
                    'time' => $time,
                ];
            }

            $timeouts = $this->httpTimeouts($hardDeadlineAt);
            $forecast = Http::acceptJson()
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'hourly' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation_probability,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    'temperature_unit' => $units['temperature_api'],
                    'wind_speed_unit' => $units['wind_api'],
                    'precipitation_unit' => $units['precipitation_api'],
                    'timezone' => 'auto',
                    'start_date' => $date,
                    'end_date' => $date,
                ]);

            if (! $forecast->successful()) {
                Log::warning('Open-Meteo hourly forecast failed.', [
                    ...$logContext,
                    'status' => $forecast->status(),
                    'body' => mb_substr($forecast->body(), 0, 1000),
                    'location_query' => $locationQuery,
                    'date' => $date,
                    'time' => $time,
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                ]);

                return $this->openMeteoFailureResult(
                    'weather_hourly_forecast_failed',
                    'weather_hourly_forecast',
                    details: ['stage' => 'forecast', 'status' => $forecast->status()],
                );
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_hourly_forecast_non_json', 'weather_hourly_forecast', details: ['stage' => 'forecast_response']);
            }

            $hourly = data_get($decoded, 'hourly');
            if (! is_array($hourly)) {
                return $this->openMeteoFailureResult('weather_hourly_forecast_missing', 'weather_hourly_forecast', details: ['stage' => 'forecast_response']);
            }

            $hourlyIndex = $this->hourlyForecastIndex($hourly, $date, $time);
            if ($hourlyIndex === null) {
                return $this->openMeteoFailureResult('weather_hourly_time_missing', 'weather_hourly_forecast', details: ['stage' => 'forecast_response']);
            }

            $placeName = $this->openMeteoPlaceName($place);
            $matchedDateTime = (string) $this->hourlyValue($hourly, 'time', $hourlyIndex);
            $matchedTime = str_contains($matchedDateTime, 'T')
                ? (string) str($matchedDateTime)->after('T')->substr(0, 5)
                : $time;
            $text = $this->openMeteoHourlyForecastText($placeName, $date, $time, $matchedTime, $hourly, $hourlyIndex, $units);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_hourly_forecast_empty', 'weather_hourly_forecast', details: ['stage' => 'forecast_response']);
            }

            $weatherCode = $this->hourlyValue($hourly, 'weather_code', $hourlyIndex);
            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_hourly_forecast',
                'query' => $logContext['query'] ?? $locationQuery,
                'location' => $placeName,
                'date' => $date,
                'time' => $time,
                'text' => $text,
                'weather' => [
                    'units' => $units['system'],
                    'time' => $this->hourlyValue($hourly, 'time', $hourlyIndex),
                    'requested_time' => $time,
                    'matched_time' => $matchedTime,
                    'is_exact_time' => $matchedTime === $time,
                    'temperature_'.$units['temperature_suffix'] => $this->roundedWeatherValue($this->hourlyValue($hourly, 'temperature_2m', $hourlyIndex)),
                    'apparent_temperature_'.$units['temperature_suffix'] => $this->roundedWeatherValue($this->hourlyValue($hourly, 'apparent_temperature', $hourlyIndex)),
                    'relative_humidity_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'relative_humidity_2m', $hourlyIndex)),
                    'precipitation_probability_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation_probability', $hourlyIndex)),
                    'precipitation_'.$units['precipitation_suffix'] => $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation', $hourlyIndex), 2),
                    'weather_code' => $weatherCode,
                    'description' => $this->openMeteoWeatherCodeDescription((int) ($weatherCode ?? -1)),
                    'cloud_cover_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'cloud_cover', $hourlyIndex)),
                    'wind_speed_'.$units['wind_suffix'] => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_speed_10m', $hourlyIndex)),
                    'wind_direction_degrees' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_direction_10m', $hourlyIndex)),
                    'wind_gusts_'.$units['wind_suffix'] => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_gusts_10m', $hourlyIndex)),
                ],
                'sources' => [[
                    'title' => 'Open-Meteo Forecast API',
                    'url' => 'https://open-meteo.com/',
                ]],
                'cached' => false,
            ];

            if ($useCache) {
                Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.weather_warm_cache_seconds', 300)));
            }

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Open-Meteo hourly forecast transport failed.', [
                ...$logContext,
                'location_query' => $locationQuery,
                'date' => $date,
                'time' => $time,
                'exception' => $exception->getMessage(),
            ]);

            return $this->openMeteoFailureResult('weather_lookup_timeout', 'weather_hourly_forecast', details: ['stage' => 'weather_pipeline']);
        }
    }

    private function dailyForecast(
        string $locationQuery,
        string $date,
        array $logContext = [],
        ?Carbon $hardDeadlineAt = null,
    ): array {
        $units = $this->openMeteoUnits($logContext);
        $locationQuery = $this->structuredWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_forecast',
                'error_code' => 'weather_location_missing',
                'required_fields' => ['location'],
            ];
        }

        $date = $this->absoluteWeatherDate($date);
        if ($date === null) {
            return $this->openMeteoFailureResult('weather_forecast_date_invalid', 'weather_forecast');
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_daily_forecast:'.sha1(mb_strtolower(
            $this->weatherLocationCacheIdentity($locationQuery, $logContext).'|'.$date,
        ));
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return [
                    ...$cached,
                    'cached' => true,
                ];
            }
        }

        try {
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext, $hardDeadlineAt);
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->openMeteoFailureResult('external_lookup_deadline', 'weather_forecast');
            }
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult(
                    (string) $geocodeResult['error_code'],
                    'weather_forecast',
                    (array) ($geocodeResult['candidates'] ?? []),
                    $this->geocodeFailureDetails($geocodeResult),
                );
            }

            $place = $geocodeResult['place'] ?? null;
            if (! is_array($place)) {
                return [
                    'ok' => false,
                    'tool' => 'external_lookup',
                    'provider' => 'open_meteo',
                    'kind' => 'weather_forecast',
                    'error_code' => 'weather_location_not_found',
                    'query' => $logContext['query'] ?? null,
                    'location' => $locationQuery,
                    'date' => $date,
                ];
            }

            $timeouts = $this->httpTimeouts($hardDeadlineAt);
            $forecast = Http::acceptJson()
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,wind_speed_10m_max',
                    'temperature_unit' => $units['temperature_api'],
                    'wind_speed_unit' => $units['wind_api'],
                    'precipitation_unit' => $units['precipitation_api'],
                    'timezone' => 'auto',
                    'start_date' => $date,
                    'end_date' => $date,
                ]);

            if (! $forecast->successful()) {
                Log::warning('Open-Meteo daily forecast failed.', [
                    ...$logContext,
                    'status' => $forecast->status(),
                    'body' => mb_substr($forecast->body(), 0, 1000),
                    'location_query' => $locationQuery,
                    'date' => $date,
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                ]);

                return $this->openMeteoFailureResult(
                    'weather_forecast_failed',
                    'weather_forecast',
                    details: ['stage' => 'forecast', 'status' => $forecast->status()],
                );
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_forecast_non_json', 'weather_forecast', details: ['stage' => 'forecast_response']);
            }

            $daily = data_get($decoded, 'daily');
            if (! is_array($daily)) {
                return $this->openMeteoFailureResult('weather_forecast_missing', 'weather_forecast', details: ['stage' => 'forecast_response']);
            }

            $placeName = $this->openMeteoPlaceName($place);
            $text = $this->openMeteoDailyForecastText($placeName, $date, $daily, $units);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_forecast_empty', 'weather_forecast', details: ['stage' => 'forecast_response']);
            }

            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_forecast',
                'query' => $logContext['query'] ?? $locationQuery,
                'location' => $placeName,
                'date' => $date,
                'text' => $text,
                'weather' => [
                    'units' => $units['system'],
                    'date' => $this->dailyValue($daily, 'time') ?? $date,
                    'temperature_max_'.$units['temperature_suffix'] => $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_max')),
                    'temperature_min_'.$units['temperature_suffix'] => $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_min')),
                    'precipitation_probability_max_percent' => $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_probability_max')),
                    'precipitation_sum_'.$units['precipitation_suffix'] => $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_sum'), 2),
                    'weather_code' => $this->dailyValue($daily, 'weather_code'),
                    'description' => $this->openMeteoWeatherCodeDescription((int) ($this->dailyValue($daily, 'weather_code') ?? -1)),
                    'wind_speed_max_'.$units['wind_suffix'] => $this->roundedWeatherValue($this->dailyValue($daily, 'wind_speed_10m_max')),
                ],
                'sources' => [[
                    'title' => 'Open-Meteo Forecast API',
                    'url' => 'https://open-meteo.com/',
                ]],
                'cached' => false,
            ];

            if ($useCache) {
                Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.weather_warm_cache_seconds', 300)));
            }

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Open-Meteo daily forecast transport failed.', [
                ...$logContext,
                'location_query' => $locationQuery,
                'date' => $date,
                'exception' => $exception->getMessage(),
            ]);

            return $this->openMeteoFailureResult('weather_lookup_timeout', 'weather_forecast', details: ['stage' => 'weather_pipeline']);
        }
    }

    private function openMeteoGeocodePlace(
        string $locationQuery,
        array $logContext = [],
        ?Carbon $hardDeadlineAt = null,
    ): array {
        $coordinates = $this->trustedCoordinates($logContext);
        if ($coordinates !== null) {
            $locationLabel = (string) ($logContext['location_label'] ?? '');

            return [
                'place' => [
                    'name' => $this->structuredWeatherLocation($locationLabel) ?: $locationQuery,
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                ],
                'error_code' => null,
            ];
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_geocode:'.sha1(mb_strtolower($locationQuery));
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $parsed = $this->parseWeatherLocationForGeocoding($locationQuery);
        $query = [
            'name' => $parsed['name'],
            'count' => 10,
            'language' => 'en',
            'format' => 'json',
        ];
        if (($parsed['country_code'] ?? null) !== null) {
            $query['countryCode'] = $parsed['country_code'];
        }

        if ($this->deadlineExpired($hardDeadlineAt)) {
            return ['place' => null, 'error_code' => 'external_lookup_deadline', 'stage' => 'geocode'];
        }
        $timeouts = $this->httpTimeouts($hardDeadlineAt);
        $geocode = Http::acceptJson()
            ->connectTimeout($timeouts['connect'])
            ->timeout($timeouts['total'])
            ->get('https://geocoding-api.open-meteo.com/v1/search', $query);

        if (! $geocode->successful()) {
            Log::warning('Open-Meteo geocode failed.', [
                ...$logContext,
                'status' => $geocode->status(),
                'body' => mb_substr($geocode->body(), 0, 1000),
                'location_query' => $locationQuery,
            ]);

            return [
                'place' => null,
                'error_code' => 'weather_geocode_failed',
                'stage' => 'geocode',
                'status' => $geocode->status(),
            ];
        }

        $results = collect((array) $geocode->json('results'))
            ->filter(fn (mixed $place): bool => is_array($place) && is_numeric($place['latitude'] ?? null) && is_numeric($place['longitude'] ?? null));

        $region = $parsed['region'];
        if ($region !== null) {
            $results = $results->filter(function (mixed $place) use ($region): bool {
                if (! is_array($place)) {
                    return false;
                }

                return mb_strtolower((string) ($place['admin1'] ?? '')) === mb_strtolower($region)
                    || mb_strtolower((string) ($place['country'] ?? '')) === mb_strtolower($region)
                    || mb_strtolower((string) ($place['country_code'] ?? '')) === mb_strtolower($region);
            })->values();
        }

        $place = $results->count() === 1 ? $results->first() : null;
        $candidates = $results
            ->take(5)
            ->map(fn (array $candidate): array => [
                'name' => $this->openMeteoPlaceName($candidate),
                'latitude' => (float) $candidate['latitude'],
                'longitude' => (float) $candidate['longitude'],
            ])
            ->values()
            ->all();
        $result = [
            'place' => is_array($place) ? $place : null,
            'error_code' => $results->count() > 1
                ? 'weather_location_ambiguous'
                : ($results->isEmpty() ? 'weather_location_not_found' : null),
            'candidates' => $results->count() > 1 ? $candidates : [],
            'stage' => 'geocode',
        ];
        if ($useCache) {
            Cache::put($cacheKey, $result, now()->addHours(is_array($place) ? 24 : 1));
        }

        return $result;
    }

    private function parseWeatherLocationForGeocoding(string $location): array
    {
        $location = $this->structuredWeatherLocation($location);
        $name = $location;
        $region = null;
        $countryCode = null;

        if (str_contains($location, ',')) {
            [$city, $suffix] = array_pad(array_map('trim', explode(',', $location, 2)), 2, '');
            if ($city !== '') {
                $name = $city;
            }
            $region = $this->usStateName($suffix) ?? ($suffix !== '' ? $suffix : null);
            $countryCode = $this->usStateName($suffix) !== null ? 'US' : null;
        } else {
            $tokens = preg_split('/\s+/', $location) ?: [];
            for ($length = min(2, count($tokens) - 1); $length >= 1; $length--) {
                $suffix = implode(' ', array_slice($tokens, -$length));
                $state = $this->usStateName($suffix);
                if ($state !== null) {
                    $name = trim(implode(' ', array_slice($tokens, 0, -$length)));
                    $region = $state;
                    $countryCode = 'US';
                    break;
                }
            }
        }

        return [
            'name' => $name !== '' ? $name : $location,
            'region' => $region,
            'country_code' => $countryCode,
        ];
    }

    private function structuredWeatherIntentPresent(array $arguments): bool
    {
        $kind = mb_strtolower(trim((string) ($arguments['kind'] ?? '')));

        return in_array($kind, ['weather', 'forecast'], true);
    }

    /**
     * Validate the canonical typed-weather boundary without interpreting any
     * temporal prose.
     *
     * @return array{kind:string,date:?string,time:?string,error_code:?string,unsupported_fields?:list<string>}
     */
    private function strictStructuredWeatherTemporal(array $arguments): array
    {
        $kind = mb_strtolower(trim((string) ($arguments['kind'] ?? '')));
        $unsupportedFields = array_values(array_filter(
            ['domain', 'lookup_domain', 'category', 'intent', 'weather_intent', 'weather_location', 'target_date', 'forecast_date', 'target_time', 'forecast_time', 'timezone'],
            fn (string $field): bool => array_key_exists($field, $arguments),
        ));
        if ($unsupportedFields !== []) {
            return [
                'kind' => $kind,
                'date' => null,
                'time' => null,
                'error_code' => 'typed_weather_arguments_invalid',
                'unsupported_fields' => $unsupportedFields,
            ];
        }
        if (! in_array($kind, ['weather', 'forecast'], true)) {
            return [
                'kind' => $kind,
                'date' => null,
                'time' => null,
                'error_code' => 'typed_weather_kind_invalid',
            ];
        }

        $dates = [];
        if (array_key_exists('date', $arguments)) {
            $value = is_string($arguments['date'])
                ? $this->absoluteWeatherDate($arguments['date'])
                : null;
            if ($value === null) {
                return [
                    'kind' => $kind,
                    'date' => null,
                    'time' => null,
                    'error_code' => 'weather_forecast_date_invalid',
                ];
            }
            $dates[] = $value;
        }

        $times = [];
        if (array_key_exists('time', $arguments)) {
            $value = is_string($arguments['time'])
                ? $this->absoluteWeatherTime($arguments['time'])
                : null;
            if ($value === null) {
                return [
                    'kind' => $kind,
                    'date' => $dates[0] ?? null,
                    'time' => null,
                    'error_code' => 'weather_hourly_datetime_invalid',
                ];
            }
            $times[] = $value;
        }

        if ($kind === 'weather' && ($dates !== [] || $times !== [])) {
            return [
                'kind' => $kind,
                'date' => null,
                'time' => null,
                'error_code' => 'weather_current_temporal_not_allowed',
            ];
        }
        if ($kind === 'forecast' && $dates === []) {
            return [
                'kind' => $kind,
                'date' => null,
                'time' => null,
                'error_code' => 'weather_forecast_date_missing',
            ];
        }

        return [
            'kind' => $kind,
            'date' => $dates[0] ?? null,
            'time' => $times[0] ?? null,
            'error_code' => null,
        ];
    }

    private function absoluteWeatherDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        return $value;
    }

    private function absoluteWeatherTime(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : null;
    }

    private function structuredWeatherLocation(string $location): string
    {
        return str($location)->squish()->toString();
    }

    private function canonicalWeatherUnitSystem(array $arguments, bool $required = false): ?string
    {
        if (! array_key_exists('units', $arguments)) {
            return $required ? null : 'imperial';
        }

        $units = $arguments['units'];

        return is_string($units) && in_array($units, ['imperial', 'metric'], true)
            ? $units
            : null;
    }

    /**
     * @return array{
     *     system:string,
     *     temperature_api:string,
     *     temperature_label:string,
     *     temperature_suffix:string,
     *     wind_api:string,
     *     wind_label:string,
     *     wind_suffix:string,
     *     precipitation_api:string,
     *     precipitation_label:string,
     *     precipitation_suffix:string
     * }
     */
    private function openMeteoUnits(array $context): array
    {
        if (($context['units'] ?? 'imperial') === 'metric') {
            return [
                'system' => 'metric',
                'temperature_api' => 'celsius',
                'temperature_label' => '°C',
                'temperature_suffix' => 'c',
                'wind_api' => 'kmh',
                'wind_label' => 'km/h',
                'wind_suffix' => 'kmh',
                'precipitation_api' => 'mm',
                'precipitation_label' => 'mm',
                'precipitation_suffix' => 'mm',
            ];
        }

        return [
            'system' => 'imperial',
            'temperature_api' => 'fahrenheit',
            'temperature_label' => '°F',
            'temperature_suffix' => 'f',
            'wind_api' => 'mph',
            'wind_label' => 'mph',
            'wind_suffix' => 'mph',
            'precipitation_api' => 'inch',
            'precipitation_label' => 'inches',
            'precipitation_suffix' => 'inches',
        ];
    }

    /** @return array{latitude:float,longitude:float}|null */
    private function trustedCoordinates(array $context): ?array
    {
        $latitude = $context['latitude'] ?? null;
        $longitude = $context['longitude'] ?? null;
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    private function weatherLocationCacheIdentity(string $locationQuery, array $context): string
    {
        $unitSystem = $this->openMeteoUnits($context)['system'];
        $coordinates = $this->trustedCoordinates($context);
        if ($coordinates === null) {
            return $locationQuery.'|'.$unitSystem;
        }

        return $locationQuery.'|'.sprintf('%.6F,%.6F', $coordinates['latitude'], $coordinates['longitude']).'|'.$unitSystem;
    }

    private function openMeteoCurrentWeatherText(string $placeName, array $current, array $units): string
    {
        $temperature = $this->roundedWeatherValue(data_get($current, 'temperature_2m'));
        if ($temperature === null) {
            return '';
        }

        $description = $this->openMeteoWeatherCodeDescription((int) data_get($current, 'weather_code', -1));
        $parts = ["It's {$temperature}{$units['temperature_label']} and {$description} in {$placeName} right now."];

        $feelsLike = $this->roundedWeatherValue(data_get($current, 'apparent_temperature'));
        $humidity = $this->roundedWeatherValue(data_get($current, 'relative_humidity_2m'));
        $windSpeed = $this->roundedWeatherValue(data_get($current, 'wind_speed_10m'));
        $windDirection = $this->compassDirection(data_get($current, 'wind_direction_10m'));
        $precipitation = $this->roundedWeatherValue(data_get($current, 'precipitation'), 2);

        $details = [];
        if ($feelsLike !== null && $feelsLike !== $temperature) {
            $details[] = "feels like {$feelsLike}{$units['temperature_label']}";
        }
        if ($humidity !== null) {
            $details[] = "humidity is {$humidity}%";
        }
        if ($windSpeed !== null) {
            $wind = "wind is {$windSpeed} {$units['wind_label']}";
            if ($windDirection !== null) {
                $wind .= " from the {$windDirection}";
            }
            $details[] = $wind;
        }
        if ($precipitation !== null && $precipitation > 0) {
            $details[] = "recent precipitation is {$precipitation} {$units['precipitation_label']}";
        }

        if ($details !== []) {
            $parts[] = ucfirst(implode(', ', $details)).'.';
        }

        return implode(' ', $parts);
    }

    private function openMeteoHourlyForecastText(string $placeName, string $date, string $requestedTime, string $matchedTime, array $hourly, int $index, array $units): string
    {
        $temperature = $this->roundedWeatherValue($this->hourlyValue($hourly, 'temperature_2m', $index));
        if ($temperature === null) {
            return '';
        }

        $weatherCode = $this->hourlyValue($hourly, 'weather_code', $index);
        $description = $this->openMeteoWeatherCodeDescription((int) ($weatherCode ?? -1));
        $details = [];
        $precipitationChance = $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation_probability', $index));
        if ($precipitationChance !== null) {
            $details[] = "a {$precipitationChance}% chance of precipitation";
        }
        $windSpeed = $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_speed_10m', $index));
        if ($windSpeed !== null) {
            $details[] = "winds around {$windSpeed} {$units['wind_label']}";
        }

        $text = $requestedTime === $matchedTime
            ? "At {$requestedTime} on {$date} in {$placeName}, expect {$temperature}{$units['temperature_label']} and {$description}"
            : "For {$date} at {$requestedTime} in {$placeName}, the nearest hourly forecast at {$matchedTime} is {$temperature}{$units['temperature_label']} and {$description}";
        if ($details !== []) {
            $text .= ', with '.implode(' and ', $details);
        }

        return $text.'.';
    }

    private function openMeteoDailyForecastText(string $placeName, string $date, array $daily, array $units): string
    {
        $high = $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_max'));
        $low = $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_min'));
        if ($high === null && $low === null) {
            return '';
        }

        $description = $this->openMeteoWeatherCodeDescription((int) ($this->dailyValue($daily, 'weather_code') ?? -1));
        $parts = ["{$date} in {$placeName} should be {$description}."];

        $temps = [];
        if ($high !== null) {
            $temps[] = "high {$high}{$units['temperature_label']}";
        }
        if ($low !== null) {
            $temps[] = "low {$low}{$units['temperature_label']}";
        }
        if ($temps !== []) {
            $parts[] = ucfirst(implode(', ', $temps)).'.';
        }

        $precipChance = $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_probability_max'));
        $precipSum = $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_sum'), 2);
        $windMax = $this->roundedWeatherValue($this->dailyValue($daily, 'wind_speed_10m_max'));
        $details = [];
        if ($precipChance !== null) {
            $details[] = "precipitation chance up to {$precipChance}%";
        } elseif ($precipSum !== null && $precipSum > 0) {
            $details[] = "precipitation around {$precipSum} {$units['precipitation_label']}";
        }
        if ($windMax !== null) {
            $details[] = "wind up to {$windMax} {$units['wind_label']}";
        }
        if ($details !== []) {
            $parts[] = ucfirst(implode(', ', $details)).'.';
        }

        return implode(' ', $parts);
    }

    private function dailyValue(array $daily, string $key): mixed
    {
        $value = data_get($daily, $key);
        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }

    private function hourlyForecastIndex(array $hourly, string $date, string $time): ?int
    {
        $times = data_get($hourly, 'time');
        if (! is_array($times) || $times === []) {
            return null;
        }

        $target = $date.'T'.$time;
        $exact = array_search($target, $times, true);
        if ($exact !== false) {
            return (int) $exact;
        }

        try {
            // Open-Meteo's hourly values are local wall-clock strings without offsets.
            // Compare both sides in the same neutral timezone so the app/test timezone
            // cannot shift a requested half hour by several hours.
            $targetAt = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        $closestIndex = null;
        $closestMinutes = null;
        foreach ($times as $index => $candidate) {
            if (! is_string($candidate) || ! str_starts_with($candidate, $date.'T')) {
                continue;
            }

            try {
                $candidateAt = Carbon::createFromFormat('Y-m-d\TH:i', $candidate, 'UTC');
            } catch (\Throwable) {
                continue;
            }

            $minutes = abs($candidateAt->diffInMinutes($targetAt, false));
            if ($closestMinutes === null || $minutes < $closestMinutes) {
                $closestMinutes = $minutes;
                $closestIndex = (int) $index;
            }
        }

        return $closestMinutes !== null && $closestMinutes <= 60 ? $closestIndex : null;
    }

    private function hourlyValue(array $hourly, string $key, int $index): mixed
    {
        $values = data_get($hourly, $key);

        return is_array($values) ? ($values[$index] ?? null) : null;
    }

    private function openMeteoPlaceName(array $place): string
    {
        $name = trim((string) ($place['name'] ?? ''));
        $admin = trim((string) ($place['admin1'] ?? ''));
        $country = trim((string) ($place['country_code'] ?? $place['country'] ?? ''));

        return collect([$name, $admin, $country])
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function openMeteoWeatherCodeDescription(int $code): string
    {
        return match ($code) {
            0 => 'clear',
            1 => 'mostly clear',
            2 => 'partly cloudy',
            3 => 'overcast',
            45, 48 => 'foggy',
            51, 53, 55 => 'drizzling',
            56, 57 => 'freezing drizzle',
            61, 63, 65 => 'raining',
            66, 67 => 'freezing rain',
            71, 73, 75 => 'snowing',
            77 => 'snow grains',
            80, 81, 82 => 'showery',
            85, 86 => 'snow showers',
            95 => 'stormy',
            96, 99 => 'stormy with hail',
            default => 'reporting current conditions',
        };
    }

    private function roundedWeatherValue(mixed $value, int $precision = 0): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, $precision);
    }

    private function compassDirection(mixed $degrees): ?string
    {
        if (! is_numeric($degrees)) {
            return null;
        }

        $directions = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        $index = (int) round(((float) $degrees % 360) / 45) % 8;

        return $directions[$index];
    }

    private function deadlineExpired(?Carbon $hardDeadlineAt): bool
    {
        return $hardDeadlineAt instanceof Carbon && ! $hardDeadlineAt->isFuture();
    }

    /** @return array{connect:float,total:float} */
    private function httpTimeouts(?Carbon $hardDeadlineAt): array
    {
        $remainingSeconds = $hardDeadlineAt instanceof Carbon
            ? max(0.001, now()->diffInMilliseconds($hardDeadlineAt, false) / 1000)
            : INF;
        $total = max(0.001, min(
            max(0.001, (float) config('services.hermes_runtime.weather_lookup_timeout', 6)),
            $remainingSeconds,
        ));
        $connect = max(0.001, min(
            max(0.001, (float) config('services.hermes_runtime.weather_lookup_connect_timeout', 3)),
            $total,
        ));

        return ['connect' => $connect, 'total' => $total];
    }

    private function openMeteoFailureResult(
        string $errorCode,
        string $kind = 'weather_current',
        array $candidates = [],
        array $details = [],
    ): array {
        $result = [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => 'open_meteo',
            'kind' => $kind,
            'error_code' => $errorCode,
            ...$this->weatherFailureConstraints($errorCode),
            ...$details,
        ];
        if ($candidates !== []) {
            $result['candidates'] = array_values($candidates);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function geocodeFailureDetails(array $geocodeResult): array
    {
        return array_filter([
            'stage' => $geocodeResult['stage'] ?? 'geocode',
            'status' => $geocodeResult['status'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function weatherFailureConstraints(string $errorCode): array
    {
        return match ($errorCode) {
            'typed_weather_kind_invalid' => [
                'field' => 'kind',
                'allowed_values' => ['weather', 'forecast'],
            ],
            'weather_units_invalid' => [
                'field' => 'units',
                'allowed_values' => ['imperial', 'metric'],
            ],
            'weather_forecast_date_invalid', 'weather_forecast_date_conflict' => [
                'field' => 'date',
                'required_format' => 'YYYY-MM-DD',
            ],
            'weather_forecast_date_missing' => [
                'required_fields' => ['date'],
                'required_format' => 'YYYY-MM-DD',
            ],
            'weather_current_temporal_not_allowed' => [
                'disallowed_fields' => ['date', 'time'],
            ],
            'weather_hourly_datetime_invalid' => [
                'fields' => ['date', 'time'],
                'required_formats' => [
                    'date' => 'YYYY-MM-DD',
                    'time' => 'HH:MM',
                ],
            ],
            'weather_forecast_time_conflict' => [
                'field' => 'time',
                'required_format' => 'HH:MM',
            ],
            'weather_location_missing' => [
                'required_fields' => ['location'],
            ],
            'external_lookup_deadline' => [
                'deadline_reached' => true,
            ],
            default => [],
        };
    }

    private function usStateName(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return [
            'al' => 'Alabama', 'alabama' => 'Alabama',
            'ak' => 'Alaska', 'alaska' => 'Alaska',
            'az' => 'Arizona', 'arizona' => 'Arizona',
            'ar' => 'Arkansas', 'arkansas' => 'Arkansas',
            'ca' => 'California', 'california' => 'California',
            'co' => 'Colorado', 'colorado' => 'Colorado',
            'ct' => 'Connecticut', 'connecticut' => 'Connecticut',
            'de' => 'Delaware', 'delaware' => 'Delaware',
            'fl' => 'Florida', 'florida' => 'Florida',
            'ga' => 'Georgia', 'georgia' => 'Georgia',
            'hi' => 'Hawaii', 'hawaii' => 'Hawaii',
            'id' => 'Idaho', 'idaho' => 'Idaho',
            'il' => 'Illinois', 'illinois' => 'Illinois',
            'in' => 'Indiana', 'indiana' => 'Indiana',
            'ia' => 'Iowa', 'iowa' => 'Iowa',
            'ks' => 'Kansas', 'kansas' => 'Kansas',
            'ky' => 'Kentucky', 'kentucky' => 'Kentucky',
            'la' => 'Louisiana', 'louisiana' => 'Louisiana',
            'me' => 'Maine', 'maine' => 'Maine',
            'md' => 'Maryland', 'maryland' => 'Maryland',
            'ma' => 'Massachusetts', 'massachusetts' => 'Massachusetts',
            'mi' => 'Michigan', 'michigan' => 'Michigan',
            'mn' => 'Minnesota', 'minnesota' => 'Minnesota',
            'ms' => 'Mississippi', 'mississippi' => 'Mississippi',
            'mo' => 'Missouri', 'missouri' => 'Missouri',
            'mt' => 'Montana', 'montana' => 'Montana',
            'ne' => 'Nebraska', 'nebraska' => 'Nebraska',
            'nv' => 'Nevada', 'nevada' => 'Nevada',
            'nh' => 'New Hampshire', 'new hampshire' => 'New Hampshire',
            'nj' => 'New Jersey', 'new jersey' => 'New Jersey',
            'nm' => 'New Mexico', 'new mexico' => 'New Mexico',
            'ny' => 'New York', 'new york' => 'New York',
            'nc' => 'North Carolina', 'north carolina' => 'North Carolina',
            'nd' => 'North Dakota', 'north dakota' => 'North Dakota',
            'oh' => 'Ohio', 'ohio' => 'Ohio',
            'ok' => 'Oklahoma', 'oklahoma' => 'Oklahoma',
            'or' => 'Oregon', 'oregon' => 'Oregon',
            'pa' => 'Pennsylvania', 'pennsylvania' => 'Pennsylvania',
            'ri' => 'Rhode Island', 'rhode island' => 'Rhode Island',
            'sc' => 'South Carolina', 'south carolina' => 'South Carolina',
            'sd' => 'South Dakota', 'south dakota' => 'South Dakota',
            'tn' => 'Tennessee', 'tennessee' => 'Tennessee',
            'tx' => 'Texas', 'texas' => 'Texas',
            'ut' => 'Utah', 'utah' => 'Utah',
            'vt' => 'Vermont', 'vermont' => 'Vermont',
            'va' => 'Virginia', 'virginia' => 'Virginia',
            'wa' => 'Washington', 'washington' => 'Washington',
            'wv' => 'West Virginia', 'west virginia' => 'West Virginia',
            'wi' => 'Wisconsin', 'wisconsin' => 'Wisconsin',
            'wy' => 'Wyoming', 'wyoming' => 'Wyoming',
            'dc' => 'District of Columbia', 'district of columbia' => 'District of Columbia',
        ][$normalized] ?? null;
    }
}
