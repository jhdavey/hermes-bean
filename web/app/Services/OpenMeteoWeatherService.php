<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoWeatherService
{
    public function weatherForIntent(array $arguments, string $timezone = '', array $logContext = []): ?array
    {
        if (! $this->structuredWeatherIntentPresent($arguments)) {
            return null;
        }

        $locationQuery = $this->cleanWeatherLocation((string) (
            $arguments['weather_location']
            ?? $arguments['location']
            ?? ''
        ));
        if ($locationQuery === '') {
            $locationQuery = $this->weatherLocationQuery((string) ($arguments['query'] ?? ''), '');
        }
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather',
                'error_code' => 'weather_location_missing',
                'message' => 'I need a location to check the live weather.',
            ];
        }

        $intent = mb_strtolower(trim((string) (
            $arguments['intent']
            ?? $arguments['weather_intent']
            ?? ''
        )));
        $targetTime = $this->structuredWeatherTime($arguments);
        $targetDate = $this->structuredWeatherDate($arguments, $timezone, $targetTime);
        $today = $this->weatherNow($timezone)->toDateString();
        if ($targetTime === null && $this->structuredWeatherTimePresent($arguments)) {
            return $this->openMeteoFailureResult('weather_hourly_datetime_invalid', 'weather_hourly_forecast');
        }
        if ($targetTime !== null) {
            return $this->hourlyForecast($locationQuery, $targetDate ?? $today, $targetTime, [
                ...$logContext,
                'query' => $arguments['query'] ?? null,
                'structured_weather_intent' => true,
                'timezone' => $timezone,
            ]);
        }

        $wantsForecast = str_contains($intent, 'forecast')
            || $targetDate !== null && $targetDate !== $today;

        if (! $wantsForecast) {
            return $this->currentWeather($locationQuery, [
                ...$logContext,
                'query' => $arguments['query'] ?? null,
                'structured_weather_intent' => true,
            ]);
        }

        return $this->dailyForecast($locationQuery, $targetDate ?? $today, [
            ...$logContext,
            'query' => $arguments['query'] ?? null,
            'structured_weather_intent' => true,
            'timezone' => $timezone,
        ]);
    }

    public function currentWeatherForQuery(string $query, string $context = '', string $location = '', array $logContext = []): ?array
    {
        if (! $this->looksLikeWeatherLookup($query, $context)) {
            return null;
        }

        $locationQuery = $this->weatherLocationQuery($query, $location);

        return $this->currentWeather($locationQuery, [
            ...$logContext,
            'query' => $query,
        ]);
    }

    public function currentWeather(string $locationQuery, array $logContext = []): array
    {
        $locationQuery = $this->cleanWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_current',
                'error_code' => 'weather_location_missing',
                'message' => 'I need a location to check the live weather.',
            ];
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_current_weather:'.sha1(mb_strtolower($locationQuery));
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
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext);
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult((string) $geocodeResult['error_code']);
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
                    'message' => "I couldn't find a weather location matching {$locationQuery}.",
                ];
            }

            $forecast = Http::acceptJson()
                ->connectTimeout((float) config('services.hermes_runtime.weather_lookup_connect_timeout', 3))
                ->timeout((float) config('services.hermes_runtime.weather_lookup_timeout', 6))
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    'temperature_unit' => 'fahrenheit',
                    'wind_speed_unit' => 'mph',
                    'precipitation_unit' => 'inch',
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

                return $this->openMeteoFailureResult('weather_forecast_failed');
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_forecast_non_json');
            }

            $current = data_get($decoded, 'current');
            if (! is_array($current)) {
                return $this->openMeteoFailureResult('weather_current_missing');
            }

            $placeName = $this->openMeteoPlaceName($place);
            $text = $this->openMeteoCurrentWeatherText($placeName, $current);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_current_empty');
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
                    'time' => data_get($current, 'time'),
                    'temperature_f' => $this->roundedWeatherValue(data_get($current, 'temperature_2m')),
                    'apparent_temperature_f' => $this->roundedWeatherValue(data_get($current, 'apparent_temperature')),
                    'relative_humidity_percent' => $this->roundedWeatherValue(data_get($current, 'relative_humidity_2m')),
                    'precipitation_inches' => $this->roundedWeatherValue(data_get($current, 'precipitation'), 2),
                    'weather_code' => data_get($current, 'weather_code'),
                    'description' => $this->openMeteoWeatherCodeDescription((int) data_get($current, 'weather_code', -1)),
                    'cloud_cover_percent' => $this->roundedWeatherValue(data_get($current, 'cloud_cover')),
                    'wind_speed_mph' => $this->roundedWeatherValue(data_get($current, 'wind_speed_10m')),
                    'wind_direction_degrees' => $this->roundedWeatherValue(data_get($current, 'wind_direction_10m')),
                    'wind_gusts_mph' => $this->roundedWeatherValue(data_get($current, 'wind_gusts_10m')),
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

            return $this->openMeteoFailureResult('weather_lookup_timeout');
        }
    }

    public function hourlyForecast(string $locationQuery, string $date, string $time, array $logContext = []): array
    {
        $locationQuery = $this->cleanWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_hourly_forecast',
                'error_code' => 'weather_location_missing',
                'message' => 'I need a location to check the hourly weather.',
            ];
        }

        $date = $this->normalizeForecastDate($date, (string) ($logContext['timezone'] ?? ''));
        $time = $this->normalizeWeatherTime($time);
        if ($date === null || $time === null) {
            return $this->openMeteoFailureResult('weather_hourly_datetime_invalid', 'weather_hourly_forecast');
        }

        $useCache = ! app()->runningUnitTests();
        $displayTimezone = $this->validTimezone((string) ($logContext['timezone'] ?? ''))
            ? (string) $logContext['timezone']
            : (string) config('app.timezone');
        $displayDate = $this->weatherNow($displayTimezone)->toDateString();
        $cacheKey = 'open_meteo_hourly_forecast:'.sha1(mb_strtolower($locationQuery.'|'.$date.'|'.$time.'|'.$displayTimezone.'|'.$displayDate));
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
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext);
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult((string) $geocodeResult['error_code'], 'weather_hourly_forecast');
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
                    'message' => "I couldn't find a weather location matching {$locationQuery}.",
                ];
            }

            $forecast = Http::acceptJson()
                ->connectTimeout((float) config('services.hermes_runtime.weather_lookup_connect_timeout', 3))
                ->timeout((float) config('services.hermes_runtime.weather_lookup_timeout', 6))
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'hourly' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation_probability,precipitation,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                    'temperature_unit' => 'fahrenheit',
                    'wind_speed_unit' => 'mph',
                    'precipitation_unit' => 'inch',
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

                return $this->openMeteoFailureResult('weather_hourly_forecast_failed', 'weather_hourly_forecast');
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_hourly_forecast_non_json', 'weather_hourly_forecast');
            }

            $hourly = data_get($decoded, 'hourly');
            if (! is_array($hourly)) {
                return $this->openMeteoFailureResult('weather_hourly_forecast_missing', 'weather_hourly_forecast');
            }

            $hourlyIndex = $this->hourlyForecastIndex($hourly, $date, $time);
            if ($hourlyIndex === null) {
                return $this->openMeteoFailureResult('weather_hourly_time_missing', 'weather_hourly_forecast');
            }

            $placeName = $this->openMeteoPlaceName($place);
            $matchedDateTime = (string) $this->hourlyValue($hourly, 'time', $hourlyIndex);
            $matchedTime = str_contains($matchedDateTime, 'T')
                ? (string) str($matchedDateTime)->after('T')->substr(0, 5)
                : $time;
            $text = $this->openMeteoHourlyForecastText($placeName, $date, $time, $matchedTime, $hourly, $hourlyIndex, $displayTimezone);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_hourly_forecast_empty', 'weather_hourly_forecast');
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
                    'time' => $this->hourlyValue($hourly, 'time', $hourlyIndex),
                    'requested_time' => $time,
                    'matched_time' => $matchedTime,
                    'is_exact_time' => $matchedTime === $time,
                    'temperature_f' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'temperature_2m', $hourlyIndex)),
                    'apparent_temperature_f' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'apparent_temperature', $hourlyIndex)),
                    'relative_humidity_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'relative_humidity_2m', $hourlyIndex)),
                    'precipitation_probability_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation_probability', $hourlyIndex)),
                    'precipitation_inches' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation', $hourlyIndex), 2),
                    'weather_code' => $weatherCode,
                    'description' => $this->openMeteoWeatherCodeDescription((int) ($weatherCode ?? -1)),
                    'cloud_cover_percent' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'cloud_cover', $hourlyIndex)),
                    'wind_speed_mph' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_speed_10m', $hourlyIndex)),
                    'wind_direction_degrees' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_direction_10m', $hourlyIndex)),
                    'wind_gusts_mph' => $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_gusts_10m', $hourlyIndex)),
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

            return $this->openMeteoFailureResult('weather_lookup_timeout', 'weather_hourly_forecast');
        }
    }

    public function dailyForecast(string $locationQuery, string $date, array $logContext = []): array
    {
        $locationQuery = $this->cleanWeatherLocation($locationQuery);
        if ($locationQuery === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'kind' => 'weather_forecast',
                'error_code' => 'weather_location_missing',
                'message' => 'I need a location to check the weather forecast.',
            ];
        }

        $date = $this->normalizeForecastDate($date, (string) ($logContext['timezone'] ?? ''));
        if ($date === null) {
            return $this->openMeteoFailureResult('weather_forecast_date_invalid', 'weather_forecast');
        }

        $useCache = ! app()->runningUnitTests();
        $cacheKey = 'open_meteo_daily_forecast:'.sha1(mb_strtolower($locationQuery.'|'.$date));
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
            $geocodeResult = $this->openMeteoGeocodePlace($locationQuery, $logContext);
            if (($geocodeResult['error_code'] ?? null) !== null) {
                return $this->openMeteoFailureResult((string) $geocodeResult['error_code'], 'weather_forecast');
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
                    'message' => "I couldn't find a weather location matching {$locationQuery}.",
                ];
            }

            $forecast = Http::acceptJson()
                ->connectTimeout((float) config('services.hermes_runtime.weather_lookup_connect_timeout', 3))
                ->timeout((float) config('services.hermes_runtime.weather_lookup_timeout', 6))
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,wind_speed_10m_max',
                    'temperature_unit' => 'fahrenheit',
                    'wind_speed_unit' => 'mph',
                    'precipitation_unit' => 'inch',
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

                return $this->openMeteoFailureResult('weather_forecast_failed', 'weather_forecast');
            }

            $decoded = $forecast->json();
            if (! is_array($decoded)) {
                return $this->openMeteoFailureResult('weather_forecast_non_json', 'weather_forecast');
            }

            $daily = data_get($decoded, 'daily');
            if (! is_array($daily)) {
                return $this->openMeteoFailureResult('weather_forecast_missing', 'weather_forecast');
            }

            $placeName = $this->openMeteoPlaceName($place);
            $text = $this->openMeteoDailyForecastText($placeName, $date, $daily);
            if ($text === '') {
                return $this->openMeteoFailureResult('weather_forecast_empty', 'weather_forecast');
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
                    'date' => $this->dailyValue($daily, 'time') ?? $date,
                    'temperature_max_f' => $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_max')),
                    'temperature_min_f' => $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_min')),
                    'precipitation_probability_max_percent' => $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_probability_max')),
                    'precipitation_sum_inches' => $this->roundedWeatherValue($this->dailyValue($daily, 'precipitation_sum'), 2),
                    'weather_code' => $this->dailyValue($daily, 'weather_code'),
                    'description' => $this->openMeteoWeatherCodeDescription((int) ($this->dailyValue($daily, 'weather_code') ?? -1)),
                    'wind_speed_max_mph' => $this->roundedWeatherValue($this->dailyValue($daily, 'wind_speed_10m_max')),
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

            return $this->openMeteoFailureResult('weather_lookup_timeout', 'weather_forecast');
        }
    }

    private function openMeteoGeocodePlace(string $locationQuery, array $logContext = []): array
    {
        $parsed = $this->parseWeatherLocationForGeocoding($locationQuery);
        $query = [
            'name' => $parsed['name'],
            'count' => $parsed['region'] !== null ? 10 : 1,
            'language' => 'en',
            'format' => 'json',
        ];
        if (($parsed['country_code'] ?? null) !== null) {
            $query['countryCode'] = $parsed['country_code'];
        }

        $geocode = Http::acceptJson()
            ->connectTimeout((float) config('services.hermes_runtime.weather_lookup_connect_timeout', 3))
            ->timeout((float) config('services.hermes_runtime.weather_lookup_timeout', 6))
            ->get('https://geocoding-api.open-meteo.com/v1/search', $query);

        if (! $geocode->successful()) {
            Log::warning('Open-Meteo geocode failed.', [
                ...$logContext,
                'status' => $geocode->status(),
                'body' => mb_substr($geocode->body(), 0, 1000),
                'location_query' => $locationQuery,
            ]);

            return ['place' => null, 'error_code' => 'weather_geocode_failed'];
        }

        $results = collect((array) $geocode->json('results'))
            ->filter(fn (mixed $place): bool => is_array($place) && is_numeric($place['latitude'] ?? null) && is_numeric($place['longitude'] ?? null));

        $region = $parsed['region'];
        if ($region !== null) {
            $filtered = $results->first(function (mixed $place) use ($region): bool {
                if (! is_array($place)) {
                    return false;
                }

                return mb_strtolower((string) ($place['admin1'] ?? '')) === mb_strtolower($region)
                    || mb_strtolower((string) ($place['country'] ?? '')) === mb_strtolower($region)
                    || mb_strtolower((string) ($place['country_code'] ?? '')) === mb_strtolower($region);
            });

            if (is_array($filtered)) {
                return ['place' => $filtered, 'error_code' => null];
            }
        }

        $place = $results->first();

        return ['place' => is_array($place) ? $place : null, 'error_code' => null];
    }

    private function parseWeatherLocationForGeocoding(string $location): array
    {
        $location = $this->cleanWeatherLocation($location);
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

    private function looksLikeWeatherLookup(string $query, string $context): bool
    {
        $text = mb_strtolower($query.' '.$context);

        return preg_match('/\b(weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b/', $text) === 1;
    }

    private function structuredWeatherIntentPresent(array $arguments): bool
    {
        $domain = mb_strtolower(trim((string) (
            $arguments['domain']
            ?? $arguments['lookup_domain']
            ?? $arguments['category']
            ?? ''
        )));
        $intent = mb_strtolower(trim((string) (
            $arguments['intent']
            ?? $arguments['weather_intent']
            ?? ''
        )));

        return in_array($domain, ['weather', 'forecast', 'open_meteo'], true)
            || str_contains($intent, 'weather')
            || str_contains($intent, 'forecast');
    }

    private function structuredWeatherDate(array $arguments, string $timezone, ?string $targetTime = null): ?string
    {
        $value = (string) (
            $arguments['date']
            ?? $arguments['target_date']
            ?? $arguments['forecast_date']
            ?? ''
        );
        if (trim($value) === '') {
            $query = mb_strtolower((string) ($arguments['query'] ?? ''));
            $value = match (true) {
                preg_match('/\btomorrow\b/u', $query) === 1 => 'tomorrow',
                preg_match('/\btonight\b/u', $query) === 1 && $targetTime !== null && (int) str($targetTime)->before(':')->toString() < 5 => 'tomorrow',
                preg_match('/\b(today|tonight)\b/u', $query) === 1 => 'today',
                preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $query, $match) === 1 => (string) $match[1],
                preg_match('/\b(?:(?:this|next)\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u', $query, $match) === 1 => (string) $match[0],
                preg_match('/\b(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b/u', $query, $match) === 1 => preg_replace('/(\d{1,2})(?:st|nd|rd|th)\b/u', '$1', (string) $match[0]),
                default => '',
            };
        }

        return $this->normalizeForecastDate($value, $timezone);
    }

    private function structuredWeatherTime(array $arguments): ?string
    {
        $value = (string) (
            $arguments['time']
            ?? $arguments['target_time']
            ?? $arguments['forecast_time']
            ?? ''
        );
        if (trim($value) !== '') {
            return $this->normalizeWeatherTime($value);
        }

        $query = (string) ($arguments['query'] ?? '');
        if (preg_match(
            '/\b(?:at|around|by)\s+((?:\d{1,2}(?::[0-5]\d)?\s*[ap]\.?\s*m\.?)|(?:(?:[01]?\d|2[0-3]):[0-5]\d)|(?:(?:[01]\d|2[0-3])[0-5]\d)|noon|midnight)(?=\s|[?.!,]|$)/iu',
            $query,
            $match
        ) !== 1) {
            return $this->spokenWeatherTime($query)
                ?? $this->dayPartWeatherTime($query);
        }

        return $this->normalizeWeatherTime((string) ($match[1] ?? ''));
    }

    private function dayPartWeatherTime(string $value): ?string
    {
        $hours = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
        if (preg_match(
            '/\b(?:at|around|by)\s+(\d{1,2}|'.implode('|', $hours).')(?:\s+o[\'’]?\s*clock)?\s+(?:(?:this|in\s+the)\s+)?(morning|afternoon|evening|tonight)\b/iu',
            $value,
            $match,
        ) !== 1) {
            return null;
        }

        $hourText = mb_strtolower((string) $match[1]);
        $hour = ctype_digit($hourText)
            ? (int) $hourText
            : ((int) (array_search($hourText, $hours, true) ?: 0)) + 1;
        if ($hour < 1 || $hour > 12) {
            return null;
        }

        $dayPart = mb_strtolower((string) $match[2]);
        if ($dayPart === 'tonight') {
            $hour = match (true) {
                $hour === 12 => 0,
                $hour < 5 => $hour,
                default => $hour + 12,
            };
        } else {
            $hour = $hour % 12 + ($dayPart === 'morning' ? 0 : 12);
        }

        return sprintf('%02d:00', $hour);
    }

    private function structuredWeatherTimePresent(array $arguments): bool
    {
        foreach (['time', 'target_time', 'forecast_time'] as $key) {
            if (array_key_exists($key, $arguments) && trim((string) $arguments[$key]) !== '') {
                return true;
            }
        }

        return preg_match(
            '/\b(?:at|around|by)\s+(?:(?:[01]\d|2[0-3])[0-5]\d|\d{1,2}(?::\d{1,2})?(?:\s*[ap]\.?\s*m\.?)?|\d{1,2}(?:\s+o[\'’]?\s*clock)|half\s+past|(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)(?=\s+(?:[ap]\.?\s*m\.?|today|tomorrow|this|in\s+the|morning|afternoon|evening|tonight|o[\'’]?\s*clock)))\b/iu',
            (string) ($arguments['query'] ?? ''),
        ) === 1;
    }

    private function normalizeWeatherTime(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        $spokenTime = $this->spokenWeatherTime($value, false);
        if ($spokenTime !== null) {
            return $spokenTime;
        }
        $value = preg_replace('/[.\s]+/u', '', $value) ?? $value;
        if ($value === 'noon') {
            return '12:00';
        }
        if ($value === 'midnight') {
            return '00:00';
        }
        if (preg_match('/^(\d{1,2})(?::([0-5]\d))?([ap])m$/u', $value, $parts) === 1) {
            $hour = (int) $parts[1];
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $hour = $hour % 12 + ($parts[3] === 'p' ? 12 : 0);

            return sprintf('%02d:%02d', $hour, (int) ($parts[2] ?? 0));
        }
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/u', $value, $parts) === 1) {
            return sprintf('%02d:%02d', (int) $parts[1], (int) $parts[2]);
        }
        if (preg_match('/^([01]\d|2[0-3])([0-5]\d)$/u', $value, $parts) === 1) {
            return sprintf('%02d:%02d', (int) $parts[1], (int) $parts[2]);
        }

        return null;
    }

    private function spokenWeatherTime(string $value, bool $requiresPreposition = true): ?string
    {
        $hours = 'one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve';
        $minutes = 'oh\s+five|ten|fifteen|twenty|twenty[ -]five|thirty|forty|forty[ -]five|fifty|fifty[ -]five';
        $prefix = $requiresPreposition ? '\\b(?:at|around|by)\\s+' : '^';
        $suffix = $requiresPreposition ? '(?=\\s|[?.!,]|$)' : '$';
        if (preg_match(
            '/'.$prefix.'(?:(half)\s+past\s+)?('.$hours.')(?:\s+('.$minutes.'))?\s*([ap])\.?\s*m\.?'.$suffix.'/iu',
            trim($value),
            $match,
        ) !== 1) {
            return null;
        }

        $hourValues = array_flip(['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve']);
        $hour = ((int) ($hourValues[mb_strtolower((string) $match[2])] ?? -1)) + 1;
        if ($hour < 1 || $hour > 12) {
            return null;
        }

        $minuteText = str_replace(['-', ' '], '', mb_strtolower((string) ($match[3] ?? '')));
        $minuteValues = [
            '' => 0, 'ohfive' => 5, 'ten' => 10, 'fifteen' => 15, 'twenty' => 20,
            'twentyfive' => 25, 'thirty' => 30, 'forty' => 40, 'fortyfive' => 45,
            'fifty' => 50, 'fiftyfive' => 55,
        ];
        $minute = ($match[1] ?? '') !== '' ? 30 : ($minuteValues[$minuteText] ?? null);
        if ($minute === null) {
            return null;
        }

        $hour = $hour % 12 + (mb_strtolower((string) $match[4]) === 'p' ? 12 : 0);

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function normalizeForecastDate(string $value, string $timezone = ''): ?string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $now = $this->weatherNow($timezone);
        if (in_array($value, ['today', 'tonight'], true)) {
            return $now->toDateString();
        }
        if ($value === 'tomorrow') {
            return $now->copy()->addDay()->toDateString();
        }

        try {
            return Carbon::parse($value, $this->validTimezone($timezone) ? $timezone : config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function weatherNow(string $timezone): Carbon
    {
        return now($this->validTimezone($timezone) ? $timezone : config('app.timezone'));
    }

    private function validTimezone(string $timezone): bool
    {
        if ($timezone === '') {
            return false;
        }

        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function weatherLocationQuery(string $query, string $location): string
    {
        $queryWithoutTemporalClauses = $this->weatherTextWithoutTemporalClauses($query);
        $patterns = [
            '/\b(?:weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b.*?\b(?:in|near|at)\s+(.+?)\s*[?.!]*$/iu',
            '/\b(?:weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b.*?\bfor\s+(.+?)\s*[?.!]*$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $queryWithoutTemporalClauses, $matches) === 1) {
                $candidate = $this->cleanWeatherLocation((string) ($matches[1] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $this->cleanWeatherLocation($location);
    }

    private function weatherTextWithoutTemporalClauses(string $content): string
    {
        $time = '(?:\d{1,2}(?::[0-5]\d)?\s*[ap]\.?\s*m\.?|(?:[01]?\d|2[0-3]):[0-5]\d|(?:[01]\d|2[0-3])[0-5]\d|noon|midnight)';
        $spokenHour = '(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)';
        $spokenMinute = '(?:oh\s+five|ten|fifteen|twenty|twenty[ -]five|thirty|forty|forty[ -]five|fifty|fifty[ -]five)';
        $content = preg_replace('/\b(?:at|around|by)\s+'.$time.'\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b(?:at|around|by)\s+(?:half\s+past\s+'.$spokenHour.'|'.$spokenHour.'(?:\s+'.$spokenMinute.')?)\s*[ap]\.?\s*m\.?/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b(?:at|around|by)\s+(?:\d{1,2}|'.$spokenHour.')(?:\s+o[\'’]?\s*clock)?\s+(?:(?:this|in\s+the)\s+)?(?:morning|afternoon|evening|tonight)\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening|later(?:\s+in)?\s+the\s+(?:morning|afternoon|evening))\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b(?:on\s+)?(?:(?:this|next)\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b(?:on\s+)?(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\bin\s+(?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?\b/iu', ' ', $content) ?? $content;
        $content = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $content) ?? $content;

        return str($content)->squish()->toString();
    }

    private function cleanWeatherLocation(string $location): string
    {
        $location = trim(preg_replace('/\s+/', ' ', $location) ?: '');
        $location = preg_replace('/\b(right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening)\b/i', '', $location) ?: '';
        $location = preg_replace('/\s+\b(?:at|around|by)\s+(?:\d{1,2}(?::[0-5]\d)?\s*[ap]\.?\s*m\.?|(?:[01]?\d|2[0-3]):[0-5]\d|noon|midnight)\s*.*$/iu', '', $location) ?: '';
        $location = trim($location, " \t\n\r\0\x0B,.?!'\"");

        return $location;
    }

    private function openMeteoCurrentWeatherText(string $placeName, array $current): string
    {
        $temperature = $this->roundedWeatherValue(data_get($current, 'temperature_2m'));
        if ($temperature === null) {
            return '';
        }

        $description = $this->openMeteoWeatherCodeDescription((int) data_get($current, 'weather_code', -1));
        $parts = ["It's {$temperature}°F and {$description} in {$placeName} right now."];

        $feelsLike = $this->roundedWeatherValue(data_get($current, 'apparent_temperature'));
        $humidity = $this->roundedWeatherValue(data_get($current, 'relative_humidity_2m'));
        $windSpeed = $this->roundedWeatherValue(data_get($current, 'wind_speed_10m'));
        $windDirection = $this->compassDirection(data_get($current, 'wind_direction_10m'));
        $precipitation = $this->roundedWeatherValue(data_get($current, 'precipitation'), 2);

        $details = [];
        if ($feelsLike !== null && $feelsLike !== $temperature) {
            $details[] = "feels like {$feelsLike}°F";
        }
        if ($humidity !== null) {
            $details[] = "humidity is {$humidity}%";
        }
        if ($windSpeed !== null) {
            $wind = "wind is {$windSpeed} mph";
            if ($windDirection !== null) {
                $wind .= " from the {$windDirection}";
            }
            $details[] = $wind;
        }
        if ($precipitation !== null && $precipitation > 0) {
            $details[] = "recent precipitation is {$precipitation} inches";
        }

        if ($details !== []) {
            $parts[] = ucfirst(implode(', ', $details)).'.';
        }

        return implode(' ', $parts);
    }

    private function openMeteoHourlyForecastText(string $placeName, string $date, string $requestedTime, string $matchedTime, array $hourly, int $index, string $displayTimezone): string
    {
        $temperature = $this->roundedWeatherValue($this->hourlyValue($hourly, 'temperature_2m', $index));
        if ($temperature === null) {
            return '';
        }

        $weatherCode = $this->hourlyValue($hourly, 'weather_code', $index);
        $description = $this->openMeteoWeatherCodeDescription((int) ($weatherCode ?? -1));
        $requestedTimeLabel = Carbon::createFromFormat('H:i', $requestedTime)->format('g:i A');
        $requestedTimeLabel = str_replace(':00 ', ' ', $requestedTimeLabel);
        $matchedTimeLabel = Carbon::createFromFormat('H:i', $matchedTime)->format('g:i A');
        $matchedTimeLabel = str_replace(':00 ', ' ', $matchedTimeLabel);
        $dateValue = Carbon::parse($date, $displayTimezone);
        $displayNow = $this->weatherNow($displayTimezone);
        $today = $displayNow->toDateString();
        $tomorrow = $displayNow->copy()->addDay()->toDateString();
        $dateLabel = match (true) {
            $dateValue->toDateString() === $today => 'today',
            $dateValue->toDateString() === $tomorrow => 'tomorrow',
            default => 'on '.$dateValue->format('M j'),
        };
        $details = [];
        $precipitationChance = $this->roundedWeatherValue($this->hourlyValue($hourly, 'precipitation_probability', $index));
        if ($precipitationChance !== null) {
            $details[] = "a {$precipitationChance}% chance of precipitation";
        }
        $windSpeed = $this->roundedWeatherValue($this->hourlyValue($hourly, 'wind_speed_10m', $index));
        if ($windSpeed !== null) {
            $details[] = "winds around {$windSpeed} mph";
        }

        $text = $requestedTime === $matchedTime
            ? "At {$requestedTimeLabel} {$dateLabel} in {$placeName}, expect {$temperature}°F and {$description}"
            : "Around {$requestedTimeLabel} {$dateLabel} in {$placeName}, the nearest hourly forecast ({$matchedTimeLabel}) is {$temperature}°F and {$description}";
        if ($details !== []) {
            $text .= ', with '.implode(' and ', $details);
        }

        return $text.'.';
    }

    private function openMeteoDailyForecastText(string $placeName, string $date, array $daily): string
    {
        $high = $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_max'));
        $low = $this->roundedWeatherValue($this->dailyValue($daily, 'temperature_2m_min'));
        if ($high === null && $low === null) {
            return '';
        }

        $description = $this->openMeteoWeatherCodeDescription((int) ($this->dailyValue($daily, 'weather_code') ?? -1));
        $label = Carbon::parse($date)->isTomorrow() ? 'Tomorrow' : Carbon::parse($date)->format('M j');
        $parts = ["{$label} in {$placeName} should be {$description}."];

        $temps = [];
        if ($high !== null) {
            $temps[] = "high {$high}°F";
        }
        if ($low !== null) {
            $temps[] = "low {$low}°F";
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
            $details[] = "precipitation around {$precipSum} inches";
        }
        if ($windMax !== null) {
            $details[] = "wind up to {$windMax} mph";
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

    private function openMeteoFailureResult(string $errorCode, string $kind = 'weather_current'): array
    {
        return [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => 'open_meteo',
            'kind' => $kind,
            'error_code' => $errorCode,
            'message' => $errorCode === 'weather_hourly_datetime_invalid'
                ? 'I couldn’t interpret the requested weather time. Try a specific time such as “5 PM.”'
                : 'I couldn’t retrieve the live weather just now. Please try again in a moment.',
        ];
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
