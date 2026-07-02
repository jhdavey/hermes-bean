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
        $targetDate = $this->structuredWeatherDate($arguments, $timezone);
        $today = $this->weatherNow($timezone)->toDateString();
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

    private function structuredWeatherDate(array $arguments, string $timezone): ?string
    {
        $value = (string) (
            $arguments['date']
            ?? $arguments['target_date']
            ?? $arguments['forecast_date']
            ?? ''
        );

        return $this->normalizeForecastDate($value, $timezone);
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
        $patterns = [
            '/\b(?:in|for|at|near)\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening))?\s*[?.!]*$/i',
            '/\bweather\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow))?\s*[?.!]*$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches) === 1) {
                $candidate = $this->cleanWeatherLocation((string) ($matches[1] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $this->cleanWeatherLocation($location);
    }

    private function cleanWeatherLocation(string $location): string
    {
        $location = trim(preg_replace('/\s+/', ' ', $location) ?: '');
        $location = preg_replace('/\b(right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening)\b/i', '', $location) ?: '';
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
            'message' => 'I’m still checking live weather. I’ll ask for a city or date if I need one more detail.',
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
