<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LiveLookupService
{
    public function __construct(
        private readonly AiUsageService $usageService,
        private readonly AdminSettingsService $adminSettings,
        private readonly OpenMeteoWeatherService $weatherService,
    ) {}

    /**
     * Execute a Hermes-selected provider route without inferring a second
     * intent from the query prose.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function lookupTyped(
        ConversationSession $session,
        array $arguments,
        ?Carbon $hardDeadlineAt = null,
        ?string $trustedTimezone = null,
    ): array {
        return $this->lookupInternal($session, $arguments, $hardDeadlineAt, $trustedTimezone);
    }

    /** @param array<string, mixed> $arguments */
    private function lookupInternal(
        ConversationSession $session,
        array $arguments,
        ?Carbon $hardDeadlineAt,
        ?string $trustedTimezone,
    ): array {
        $unknown = array_values(array_diff(array_keys($arguments), [
            'query', 'context', 'kind', 'location', 'latitude', 'longitude', 'date', 'time',
            'units', 'topic',
        ]));
        if ($unknown !== []) {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'error_code' => 'typed_lookup_arguments_invalid',
                'unsupported_fields' => $unknown,
            ];
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'error_code' => 'missing_query',
                'required_fields' => ['query'],
            ];
        }

        $kind = mb_strtolower(trim((string) ($arguments['kind'] ?? '')));
        $allowedKinds = ['weather', 'forecast', 'places', 'web', 'general'];
        if (! in_array($kind, $allowedKinds, true)) {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'error_code' => 'typed_lookup_kind_invalid',
                'field' => 'kind',
                'allowed_values' => $allowedKinds,
            ];
        }
        $topic = mb_strtolower(trim((string) ($arguments['topic'] ?? '')));
        $allowedTopics = ['general', 'news', 'finance'];
        if (in_array($kind, ['web', 'general'], true)
            && ! in_array($topic, $allowedTopics, true)) {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'error_code' => 'typed_lookup_topic_invalid',
                'field' => 'topic',
                'allowed_values' => $allowedTopics,
            ];
        }

        $startedAt = microtime(true);
        $user = User::findOrFail($session->user_id);
        $context = trim((string) ($arguments['context'] ?? ''));
        $location = trim((string) ($arguments['location'] ?? ''));
        if ($kind === 'places' && $location === '') {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'error_code' => 'typed_places_arguments_invalid',
                'required_fields' => ['location'],
            ];
        }
        if ($this->deadlineExpired($hardDeadlineAt)) {
            return $this->deadlineFailure();
        }
        $cacheKey = $this->cacheKey($query, $context, $location, $arguments);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return [
                ...$cached,
                'cached' => true,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        $externalPreflight = $this->usageService->preflightDirect(
            $user,
            $session->workspace_id,
            $this->adminSettings->externalLookupModel(),
            $this->usageService->estimateTokens($query.' '.$context.' '.$location),
            500,
            null,
            'external_lookup',
            ['session' => $session],
        );
        if (! $externalPreflight['allowed']) {
            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', $this->adminSettings->externalLookupModel(), [], [
                'conversation_session_id' => $session->id,
                'reason' => $externalPreflight['reason'],
                'query' => $query,
                'live_lookup_provider' => 'budget_guard',
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], ['external_lookup'], 'blocked');

            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'budget_guard',
                'error_code' => 'external_lookup_limit',
                'limit_scope' => 'external_lookup',
                'blocked' => true,
            ];
        }

        $weatherSelected = in_array($kind, ['weather', 'forecast'], true);
        if ((bool) config('services.hermes_runtime.weather_lookup_enabled', true)
            && $weatherSelected) {
            $timezone = $this->validTrustedTimezone($trustedTimezone);
            $weatherLogContext = [
                'source' => 'live_lookup_gateway',
                'session_id' => $session->id,
                'workspace_id' => $session->workspace_id,
                'timezone' => $timezone,
                'location_label' => $arguments['location'] ?? null,
                'latitude' => $arguments['latitude'] ?? null,
                'longitude' => $arguments['longitude'] ?? null,
                'units' => $arguments['units'] ?? null,
            ];
            $weatherResult = $this->weatherService->weatherForStructuredIntent(
                $arguments,
                $timezone ?? '',
                $weatherLogContext,
                $hardDeadlineAt,
            );
            if ($weatherResult !== null) {
                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
                $result = [
                    ...$weatherResult,
                    'provider' => 'open_meteo',
                    'latency_ms' => $latencyMs,
                ];
                $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'open-meteo', [
                    'tool_call_count' => 1,
                ], [
                    'conversation_session_id' => $session->id,
                    'provider' => 'open_meteo',
                    'live_lookup_provider' => 'open_meteo',
                    'query' => $query,
                    'kind' => $result['kind'] ?? null,
                    'latency_ms' => $latencyMs,
                ], ['external_lookup', 'open_meteo_weather'], ($result['ok'] ?? false) ? 'completed' : 'failed');

                if (($result['ok'] ?? false) === true) {
                    Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.live_lookup_cache_seconds', 300)));

                    return $result;
                }

                // A recognized structured weather request must not fall through to a
                // generic search provider. Search results can be about a different
                // place or time and are less trustworthy than a clear provider error.
                return $result;
            }
        }

        if ($weatherSelected) {
            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'open_meteo',
                'error_code' => (bool) config('services.hermes_runtime.weather_lookup_enabled', true)
                    ? 'typed_weather_arguments_invalid'
                    : 'weather_lookup_disabled',
                'weather_lookup_enabled' => (bool) config('services.hermes_runtime.weather_lookup_enabled', true),
            ];
        }

        if ($kind === 'places') {
            // Hermes has already resolved the user's prose into these two
            // schema fields. The provider gateway must use them verbatim and
            // never run the nonvoice place-intent parser a second time.
            $placeFailures = [];

            $placesResult = $this->googlePlacesLookup(
                $session,
                $user,
                $query,
                $context,
                $query,
                $location,
                $startedAt,
                $cacheKey,
                $hardDeadlineAt,
            );
            if ($this->shouldReturnProviderResult($placesResult)) {
                return $placesResult;
            }
            if (is_array($placesResult)) {
                $placeFailures[] = $placesResult;
            }
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }

            $placesResult = $this->osmPlacesLookup(
                $session,
                $user,
                $query,
                $context,
                $query,
                $location,
                $startedAt,
                $cacheKey,
                $hardDeadlineAt,
            );
            if ($this->shouldReturnProviderResult($placesResult)) {
                return $placesResult;
            }
            if (is_array($placesResult)) {
                $placeFailures[] = $placesResult;
            }
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }

            return $this->terminalPlacesFailure($query, $location, $placeFailures, $startedAt);
        }

        $tavilyResult = $this->tavilySearchLookup(
            $session,
            $user,
            $query,
            $context,
            $location,
            $topic,
            $startedAt,
            $cacheKey,
            $hardDeadlineAt,
        );
        if ($this->shouldReturnProviderResult($tavilyResult)) {
            return $tavilyResult;
        }
        if ($this->deadlineExpired($hardDeadlineAt)) {
            return $this->deadlineFailure();
        }

        return $this->webSearchLookup(
            $session,
            $user,
            $query,
            $context,
            $location,
            $startedAt,
            $cacheKey,
            $hardDeadlineAt,
            $this->validTrustedTimezone($trustedTimezone),
        );
    }

    public function providers(): array
    {
        $month = now()->startOfMonth();
        $providerUsage = $this->providerUsage($month);
        $providers = [
            [
                'key' => 'open_meteo',
                'label' => 'Open-Meteo',
                'category' => 'Weather',
                'connected' => (bool) config('services.hermes_runtime.weather_lookup_enabled', true),
                'configured' => true,
                'mode' => 'Direct API',
                'timeout_ms' => (int) ((float) config('services.hermes_runtime.weather_lookup_timeout', 6) * 1000),
                'notes' => 'Used for current weather plus hourly and daily forecasts. Recognized weather failures stay scoped and never fall through to unrelated web results.',
            ],
            [
                'key' => 'google_places',
                'label' => 'Google Places',
                'category' => 'Places',
                'connected' => (bool) config('services.hermes_runtime.google_places_enabled', true) && $this->googleMapsApiKey() !== '',
                'configured' => $this->googleMapsApiKey() !== '',
                'mode' => 'Places Text Search + Geocoding',
                'timeout_ms' => (int) ((float) config('services.hermes_runtime.google_places_timeout', 6) * 1000),
                'notes' => 'Primary provider for nearby businesses, addresses, branded places, and local place questions before web search.',
            ],
            [
                'key' => 'osm_places',
                'label' => 'OpenStreetMap Places',
                'category' => 'Places',
                'connected' => (bool) config('services.hermes_runtime.osm_places_enabled', true),
                'configured' => true,
                'mode' => 'Photon + ZIP centroid',
                'timeout_ms' => (int) ((float) config('services.hermes_runtime.osm_places_timeout', 5) * 1000),
                'notes' => 'Keyless fallback for nearby businesses and addresses when Google Places is unavailable.',
            ],
            [
                'key' => 'tavily_search',
                'label' => 'Tavily Search',
                'category' => 'General web',
                'connected' => (bool) config('services.hermes_runtime.tavily_search_enabled', true) && $this->tavilyApiKey() !== '',
                'configured' => $this->tavilyApiKey() !== '',
                'mode' => (string) config('services.hermes_runtime.tavily_search_depth', 'ultra-fast'),
                'timeout_ms' => (int) ((float) config('services.hermes_runtime.tavily_search_timeout', 6) * 1000),
                'notes' => 'Primary fast web lookup provider for current facts, recent information, and broad live searches.',
            ],
            [
                'key' => 'openai_web_search',
                'label' => 'OpenAI Web Search',
                'category' => 'General web',
                'connected' => $this->providerApiKey() !== '',
                'configured' => $this->providerApiKey() !== '',
                'mode' => (string) config('services.hermes_runtime.external_lookup_tool', 'web_search'),
                'timeout_ms' => (int) ((float) config('services.hermes_runtime.external_lookup_timeout', 8) * 1000),
                'notes' => 'Fallback provider for current facts, local businesses, prices, news, and broad live lookups.',
            ],
        ];

        return [
            'cache_seconds' => (int) config('services.hermes_runtime.live_lookup_cache_seconds', 300),
            'providers' => collect($providers)->map(function (array $provider) use ($providerUsage): array {
                $usage = $providerUsage[$provider['key']] ?? ['requests' => 0, 'completed' => 0, 'failed' => 0, 'blocked' => 0, 'cost' => 0.0, 'avg_latency_ms' => null, 'last_used_at' => null];

                return [...$provider, 'usage' => $usage];
            })->values()->all(),
        ];
    }

    private function googlePlacesLookup(
        ConversationSession $session,
        User $user,
        string $query,
        string $context,
        string $placeName,
        string $locationQuery,
        float $startedAt,
        string $cacheKey,
        ?Carbon $hardDeadlineAt,
    ): ?array {
        if (! (bool) config('services.hermes_runtime.google_places_enabled', true) || $this->googleMapsApiKey() === '') {
            return null;
        }

        if ($placeName === '' || $locationQuery === '') {
            return null;
        }

        try {
            $geocode = $this->googleGeocodeLocation($locationQuery, $hardDeadlineAt);
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }
            if (($geocode['error_code'] ?? null) === 'places_location_ambiguous') {
                return $this->providerFailure(
                    $user,
                    $session,
                    'google_places',
                    'google-geocoding',
                    $query,
                    'places_location_ambiguous',
                    $startedAt,
                    metadata: ['stage' => 'geocode'],
                    fallbackAllowed: false,
                    details: ['candidates' => $geocode['candidates'] ?? []],
                );
            }
            $origin = $geocode['origin'] ?? null;
            if (! is_array($origin)) {
                return $this->providerFailure($user, $session, 'google_places', 'google-geocoding', $query, 'places_location_not_found', $startedAt, null, ['stage' => 'geocode']);
            }

            $requestedPostalCode = $this->postalCodeFromLocation($locationQuery);
            $places = [];
            $searchAttempts = 0;
            $lastStatus = null;
            $lastTextQuery = null;
            $radius = max(1000, (int) config('services.hermes_runtime.google_places_radius_meters', 50000));
            $searchQueries = array_values(array_unique(array_filter([
                trim("{$placeName} {$locationQuery}"),
                $placeName,
            ])));

            foreach ($searchQueries as $textQuery) {
                if ($this->deadlineExpired($hardDeadlineAt)) {
                    return $this->deadlineFailure();
                }
                $searchAttempts++;
                $lastTextQuery = $textQuery;
                $timeouts = $this->httpTimeouts(
                    (float) config('services.hermes_runtime.google_places_connect_timeout', 2),
                    (float) config('services.hermes_runtime.google_places_timeout', 6),
                    $hardDeadlineAt,
                );
                $response = Http::acceptJson()
                    ->asJson()
                    ->withHeaders([
                        'X-Goog-Api-Key' => $this->googleMapsApiKey(),
                        'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.googleMapsUri,places.businessStatus,places.types',
                    ])
                    ->connectTimeout($timeouts['connect'])
                    ->timeout($timeouts['total'])
                    ->post('https://places.googleapis.com/v1/places:searchText', [
                        'textQuery' => $textQuery,
                        'pageSize' => 10,
                        'locationBias' => [
                            'circle' => [
                                'center' => [
                                    'latitude' => $origin['lat'],
                                    'longitude' => $origin['lon'],
                                ],
                                'radius' => $radius,
                            ],
                        ],
                    ]);

                if (! $response->successful()) {
                    $lastStatus = $response->status();

                    continue;
                }

                $candidatePlaces = collect((array) data_get($response->json(), 'places'))
                    ->map(function ($place) use ($origin, $requestedPostalCode, $placeName): array {
                        $normalized = $this->normalizeGooglePlace(is_array($place) ? $place : [], $origin, $requestedPostalCode);
                        $normalized['name_match_score'] = $this->placeNameMatchScore($normalized, $placeName);
                        $normalized['ranking_distance_meters'] = $this->placeRankingDistanceMeters($normalized);

                        return $normalized;
                    })
                    ->filter(fn (array $place): bool => ($place['name'] ?? '') !== '')
                    ->filter(fn (array $place): bool => (int) ($place['name_match_score'] ?? 0) > 0)
                    ->sortBy([
                        ['postal_code_match', 'desc'],
                        ['ranking_distance_meters', 'asc'],
                        ['name_match_score', 'desc'],
                    ])
                    ->take(5)
                    ->values()
                    ->all();

                if ($candidatePlaces !== []) {
                    $places = $candidatePlaces;

                    break;
                }
            }

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($places === []) {
                return $this->providerFailure($user, $session, 'google_places', 'google-places-text-search', $query, $lastStatus === null ? 'places_not_found' : 'places_lookup_failed', $startedAt, $lastStatus, [
                    'stage' => 'text_search',
                    'location_query' => $locationQuery,
                    'search_attempts' => $searchAttempts,
                    'last_text_query' => $lastTextQuery,
                ]);
            }

            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'google-places', [
                'tool_call_count' => 1 + $searchAttempts,
            ], [
                'conversation_session_id' => $session->id,
                'provider' => 'google',
                'live_lookup_provider' => 'google_places',
                'query' => $query,
                'place_name' => $placeName,
                'location_query' => $locationQuery,
                'search_attempts' => $searchAttempts,
                'last_text_query' => $lastTextQuery,
                'result_count' => count($places),
                'postal_code_match' => data_get($places, '0.postal_code_match'),
                'latency_ms' => $latencyMs,
            ], ['external_lookup', 'google_places']);

            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'google_places',
                'query' => $query,
                'context' => $context !== '' ? $context : null,
                'location' => $locationQuery,
                'text' => $this->placesText($places, $placeName, $locationQuery),
                'places' => $places,
                'sources' => [[
                    'title' => 'Google Places',
                    'url' => 'https://developers.google.com/maps/documentation/places/web-service/text-search',
                ]],
                'latency_ms' => $latencyMs,
            ];
            Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.live_lookup_cache_seconds', 300)));

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Live lookup Google Places transport failed.', [
                'session_id' => $session->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->providerFailure($user, $session, 'google_places', 'google-places', $query, 'places_lookup_timeout', $startedAt);
        }
    }

    private function osmPlacesLookup(
        ConversationSession $session,
        User $user,
        string $query,
        string $context,
        string $placeName,
        string $locationQuery,
        float $startedAt,
        string $cacheKey,
        ?Carbon $hardDeadlineAt,
    ): ?array {
        if (! (bool) config('services.hermes_runtime.osm_places_enabled', true)) {
            return null;
        }

        if ($placeName === '' || $locationQuery === '') {
            return null;
        }

        try {
            $originResult = $this->osmLocationOrigin($locationQuery, $hardDeadlineAt);
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }
            if (($originResult['error_code'] ?? null) === 'places_location_ambiguous') {
                return $this->providerFailure(
                    $user,
                    $session,
                    'osm_places',
                    'openstreetmap-photon',
                    $query,
                    'places_location_ambiguous',
                    $startedAt,
                    metadata: ['stage' => 'origin'],
                    fallbackAllowed: false,
                    details: ['candidates' => $originResult['candidates'] ?? []],
                );
            }
            $origin = $originResult['origin'] ?? null;
            if (! is_array($origin)) {
                return $this->providerFailure($user, $session, 'osm_places', 'openstreetmap-photon', $query, 'osm_location_not_found', $startedAt, null, ['stage' => 'origin']);
            }

            $timeouts = $this->httpTimeouts(
                (float) config('services.hermes_runtime.osm_places_connect_timeout', 2),
                (float) config('services.hermes_runtime.osm_places_timeout', 5),
                $hardDeadlineAt,
            );
            $response = Http::acceptJson()
                ->withUserAgent('HeyBean/1.0')
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get(rtrim((string) config('services.hermes_runtime.osm_photon_base', 'https://photon.komoot.io'), '/').'/api/', [
                    'q' => trim($placeName.' '.$locationQuery),
                    'limit' => 10,
                    'lat' => $origin['lat'],
                    'lon' => $origin['lon'],
                    'lang' => 'en',
                ]);

            if (! $response->successful()) {
                return $this->providerFailure($user, $session, 'osm_places', 'openstreetmap-photon', $query, 'osm_places_lookup_failed', $startedAt, $response->status(), ['stage' => 'photon']);
            }

            $requestedPostalCode = $this->postalCodeFromLocation($locationQuery);
            $places = collect((array) data_get($response->json(), 'features'))
                ->map(function ($feature) use ($origin, $requestedPostalCode, $placeName): array {
                    $normalized = $this->normalizeOsmPlace(is_array($feature) ? $feature : [], $origin, $requestedPostalCode);
                    $normalized['name_match_score'] = $this->placeNameMatchScore($normalized, $placeName);
                    $normalized['ranking_distance_meters'] = $this->placeRankingDistanceMeters($normalized);

                    return $normalized;
                })
                ->filter(fn (array $place): bool => ($place['name'] ?? '') !== '')
                ->filter(fn (array $place): bool => (int) ($place['name_match_score'] ?? 0) > 0)
                ->unique(fn (array $place): string => mb_strtolower(($place['name'] ?? '').'|'.($place['address'] ?? '')))
                ->sortBy([
                    ['postal_code_match', 'desc'],
                    ['ranking_distance_meters', 'asc'],
                    ['name_match_score', 'desc'],
                ])
                ->take(5)
                ->values()
                ->all();

            if ($places === []) {
                return $this->providerFailure($user, $session, 'osm_places', 'openstreetmap-photon', $query, 'osm_places_not_found', $startedAt, null, [
                    'stage' => 'photon',
                    'location_query' => $locationQuery,
                    'place_name' => $placeName,
                ]);
            }

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'openstreetmap-photon', [
                'tool_call_count' => 2,
            ], [
                'conversation_session_id' => $session->id,
                'provider' => 'openstreetmap',
                'live_lookup_provider' => 'osm_places',
                'query' => $query,
                'place_name' => $placeName,
                'location_query' => $locationQuery,
                'result_count' => count($places),
                'postal_code_match' => data_get($places, '0.postal_code_match'),
                'latency_ms' => $latencyMs,
            ], ['external_lookup', 'osm_places']);

            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'osm_places',
                'query' => $query,
                'context' => $context !== '' ? $context : null,
                'location' => $locationQuery,
                'text' => $this->placesText($places, $placeName, $locationQuery),
                'places' => $places,
                'sources' => [[
                    'title' => 'OpenStreetMap Photon',
                    'url' => 'https://photon.komoot.io/',
                ]],
                'latency_ms' => $latencyMs,
            ];
            Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.live_lookup_cache_seconds', 300)));

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Live lookup OpenStreetMap Places transport failed.', [
                'session_id' => $session->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->providerFailure($user, $session, 'osm_places', 'openstreetmap-photon', $query, 'osm_places_lookup_timeout', $startedAt);
        }
    }

    private function tavilySearchLookup(
        ConversationSession $session,
        User $user,
        string $query,
        string $context,
        string $location,
        string $topic,
        float $startedAt,
        string $cacheKey,
        ?Carbon $hardDeadlineAt,
    ): ?array {
        if (! (bool) config('services.hermes_runtime.tavily_search_enabled', true) || $this->tavilyApiKey() === '') {
            return null;
        }

        $searchQuery = trim(implode(' ', array_filter([$query, $location !== '' ? "near {$location}" : null])));

        try {
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }
            $timeouts = $this->httpTimeouts(
                (float) config('services.hermes_runtime.tavily_search_connect_timeout', 2),
                (float) config('services.hermes_runtime.tavily_search_timeout', 6),
                $hardDeadlineAt,
            );
            $response = Http::withToken($this->tavilyApiKey())
                ->acceptJson()
                ->asJson()
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->post('https://api.tavily.com/search', [
                    'query' => $searchQuery,
                    'search_depth' => (string) config('services.hermes_runtime.tavily_search_depth', 'ultra-fast'),
                    'topic' => $topic,
                    'include_answer' => 'basic',
                    'include_raw_content' => false,
                    'include_images' => false,
                    'include_favicon' => true,
                    'include_usage' => true,
                    'max_results' => 5,
                ]);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            if (! $response->successful()) {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_failed', $startedAt, $response->status());
            }

            $decoded = $response->json();
            if (! is_array($decoded)) {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_non_json', $startedAt);
            }

            $text = $this->tavilyText($decoded);
            if ($text === '') {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_empty', $startedAt);
            }

            $credits = (float) data_get($decoded, 'usage.credits', 0);
            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'tavily-search', [
                'tool_call_count' => 1,
            ], [
                'conversation_session_id' => $session->id,
                'provider' => 'tavily',
                'live_lookup_provider' => 'tavily_search',
                'query' => $query,
                'topic' => $topic,
                'request_id' => data_get($decoded, 'request_id'),
                'response_time' => data_get($decoded, 'response_time'),
                'credits' => $credits,
                'latency_ms' => $latencyMs,
            ], ['external_lookup', 'tavily_search']);

            $result = [
                'ok' => true,
                'tool' => 'external_lookup',
                'provider' => 'tavily_search',
                'query' => $query,
                'topic' => $topic,
                'context' => $context !== '' ? $context : null,
                'location' => $location !== '' ? $location : null,
                'text' => $text,
                'citations' => $this->tavilySources($decoded),
                'sources' => $this->tavilySources($decoded),
                'latency_ms' => $latencyMs,
            ];
            Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.live_lookup_cache_seconds', 300)));

            return $result;
        } catch (ConnectionException $exception) {
            Log::warning('Live lookup Tavily transport failed.', [
                'session_id' => $session->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_timeout', $startedAt);
        }
    }

    private function webSearchLookup(
        ConversationSession $session,
        User $user,
        string $query,
        string $context,
        string $location,
        float $startedAt,
        string $cacheKey,
        ?Carbon $hardDeadlineAt,
        ?string $trustedTimezone,
    ): array {
        $toolType = (string) config('services.hermes_runtime.external_lookup_tool', 'web_search');
        $webSearchPreflight = $this->usageService->preflightDirect(
            $user,
            $session->workspace_id,
            $this->adminSettings->externalLookupModel(),
            $this->usageService->estimateTokens($query.' '.$context.' '.$location),
            800,
            null,
            'web_search',
            ['session' => $session],
        );
        if (! $webSearchPreflight['allowed']) {
            $this->usageService->recordDirectCall($user, $session->workspace_id, 'web_search', $this->adminSettings->externalLookupModel(), [], [
                'conversation_session_id' => $session->id,
                'reason' => $webSearchPreflight['reason'],
                'query' => $query,
                'tool_type' => $toolType,
                'live_lookup_provider' => 'openai_web_search',
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], ['external_lookup', 'web_search'], 'blocked');

            return [
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'openai_web_search',
                'error_code' => 'web_search_limit',
                'limit_scope' => 'web_search',
                'blocked' => true,
            ];
        }

        $payload = [
            'model' => $this->adminSettings->externalLookupModel(),
            'tools' => [
                ['type' => $toolType],
            ],
            'tool_choice' => 'auto',
            'instructions' => 'You are a concise live lookup helper for Bean. Search the web when needed, answer only from current external results, and include citations in the response annotations when available. If results are incomplete or uncertain, say so plainly.',
            'input' => $this->externalLookupPrompt($session, $query, $context, $location, $trustedTimezone),
        ];

        $attempts = max(1, (int) config('services.hermes_runtime.external_lookup_attempts', 1));
        $response = null;
        $lastException = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return $this->deadlineFailure();
            }
            try {
                $timeouts = $this->httpTimeouts(
                    (float) config('services.hermes_runtime.external_lookup_connect_timeout', 3),
                    (float) config('services.hermes_runtime.external_lookup_timeout', 8),
                    $hardDeadlineAt,
                );
                $response = Http::withToken($this->providerApiKey())
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout($timeouts['connect'])
                    ->timeout($timeouts['total'])
                    ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/responses', $payload);
                $lastException = null;
                break;
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                Log::warning('Live lookup web search transport failed.', [
                    'session_id' => $session->id,
                    'attempt' => $attempt,
                    'attempts' => $attempts,
                    'exception' => $exception->getMessage(),
                    'key_source' => config('services.hermes_runtime.api_key_source'),
                    'api_base' => config('services.hermes_runtime.api_base'),
                    'model' => $this->adminSettings->externalLookupModel(),
                    'tool_type' => $toolType,
                ]);
            }
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($lastException !== null || $response === null) {
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_timeout', $latencyMs);
        }

        if (! $response->successful()) {
            Log::warning('Live lookup web search failed.', [
                'session_id' => $session->id,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
                'key_source' => config('services.hermes_runtime.api_key_source'),
                'api_base' => config('services.hermes_runtime.api_base'),
                'model' => $this->adminSettings->externalLookupModel(),
                'tool_type' => $toolType,
            ]);

            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_failed', $latencyMs, $response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_non_json', $latencyMs);
        }

        $text = $this->extractResponseText($decoded);
        if ($text === '') {
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_empty', $latencyMs);
        }

        $this->usageService->recordDirectCall($user, $session->workspace_id, 'web_search', (string) data_get($decoded, 'model', $this->adminSettings->externalLookupModel()), [
            ...$this->usageService->usageFromOpenAiResponse($decoded),
            'tool_call_count' => 1,
        ], [
            'conversation_session_id' => $session->id,
            'query' => $query,
            'tool_type' => $toolType,
            'response_id' => data_get($decoded, 'id'),
            'live_lookup_provider' => 'openai_web_search',
            'latency_ms' => $latencyMs,
        ], ['external_lookup', 'web_search']);

        $result = [
            'ok' => true,
            'tool' => 'external_lookup',
            'provider' => 'openai_web_search',
            'query' => $query,
            'context' => $context !== '' ? $context : null,
            'location' => $location !== '' ? $location : null,
            'text' => $text,
            'citations' => $this->extractResponseCitations($decoded),
            'sources' => $this->extractResponseSources($decoded),
            'model' => data_get($decoded, 'model'),
            'latency_ms' => $latencyMs,
        ];
        Cache::put($cacheKey, $result, now()->addSeconds((int) config('services.hermes_runtime.live_lookup_cache_seconds', 300)));

        return $result;
    }

    private function providerFailure(
        User $user,
        ConversationSession $session,
        string $providerKey,
        string $model,
        string $query,
        string $errorCode,
        float $startedAt,
        ?int $status = null,
        array $metadata = [],
        bool $fallbackAllowed = true,
        array $details = [],
    ): array {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = [
            ...$metadata,
            'conversation_session_id' => $session->id,
            'provider' => str_replace(['_places', '_search'], '', $providerKey),
            'live_lookup_provider' => $providerKey,
            'query' => $query,
            'error_code' => $errorCode,
            'latency_ms' => $latencyMs,
        ];
        if ($status !== null) {
            $payload['status'] = $status;
        }

        $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', $model, [
            'tool_call_count' => 1,
        ], $payload, ['external_lookup', $providerKey], 'failed');

        $result = [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => $providerKey,
            'error_code' => $errorCode,
            'latency_ms' => $latencyMs,
            'fallback_allowed' => $fallbackAllowed,
            ...$details,
        ];
        if ($status !== null) {
            $result['status'] = $status;
        }

        return $result;
    }

    private function shouldReturnProviderResult(?array $result): bool
    {
        if ($result === null) {
            return false;
        }

        return ($result['ok'] ?? false) === true || ($result['fallback_allowed'] ?? false) !== true;
    }

    /**
     * @param  list<array<string,mixed>>  $providerFailures
     * @return array<string,mixed>
     */
    private function terminalPlacesFailure(
        string $query,
        string $location,
        array $providerFailures,
        float $startedAt,
    ): array {
        $failures = collect($providerFailures)
            ->map(fn (array $failure): array => array_filter([
                'provider' => $failure['provider'] ?? null,
                'error_code' => $failure['error_code'] ?? null,
                'status' => $failure['status'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();
        $notFoundCodes = [
            'places_location_not_found',
            'places_not_found',
            'osm_location_not_found',
            'osm_places_not_found',
        ];
        $onlyNotFound = $failures !== [] && collect($failures)->every(
            fn (array $failure): bool => in_array($failure['error_code'] ?? null, $notFoundCodes, true),
        );

        return [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => 'places',
            'kind' => 'places',
            'query' => $query,
            'location' => $location,
            'error_code' => $failures === []
                ? 'places_lookup_unavailable'
                : ($onlyNotFound ? 'places_not_found' : 'places_lookup_failed'),
            'fallback_allowed' => false,
            'provider_failures' => $failures,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function deadlineExpired(?Carbon $hardDeadlineAt): bool
    {
        return $hardDeadlineAt instanceof Carbon && ! $hardDeadlineAt->isFuture();
    }

    /** @return array{connect:float,total:float} */
    private function httpTimeouts(
        float $configuredConnectSeconds,
        float $configuredTotalSeconds,
        ?Carbon $hardDeadlineAt,
    ): array {
        $remainingSeconds = $hardDeadlineAt instanceof Carbon
            ? max(0.001, now()->diffInMilliseconds($hardDeadlineAt, false) / 1000)
            : INF;
        $total = max(0.001, min(max(0.001, $configuredTotalSeconds), $remainingSeconds));
        $connect = max(0.001, min(max(0.001, $configuredConnectSeconds), $total));

        return ['connect' => $connect, 'total' => $total];
    }

    /** @return array<string,mixed> */
    private function deadlineFailure(): array
    {
        return [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => 'deadline_guard',
            'error_code' => 'external_lookup_deadline',
            'deadline_reached' => true,
            'fallback_allowed' => false,
        ];
    }

    /** @return array{origin:?array,error_code:?string,candidates:list<array<string,mixed>>} */
    private function googleGeocodeLocation(string $locationQuery, ?Carbon $hardDeadlineAt): array
    {
        $params = [
            'address' => $this->googleLocationAddress($locationQuery),
            'key' => $this->googleMapsApiKey(),
            'region' => 'us',
        ];
        $postalCode = $this->postalCodeFromLocation($locationQuery);
        if ($postalCode !== null) {
            $params['components'] = "postal_code:{$postalCode}|country:US";
        }

        $timeouts = $this->httpTimeouts(
            (float) config('services.hermes_runtime.google_places_connect_timeout', 2),
            (float) config('services.hermes_runtime.google_places_timeout', 6),
            $hardDeadlineAt,
        );
        $response = Http::acceptJson()
            ->connectTimeout($timeouts['connect'])
            ->timeout($timeouts['total'])
            ->get('https://maps.googleapis.com/maps/api/geocode/json', $params);

        if (! $response->successful() || data_get($response->json(), 'status') !== 'OK') {
            return ['origin' => null, 'error_code' => 'places_location_not_found', 'candidates' => []];
        }

        $candidates = collect((array) data_get($response->json(), 'results'))
            ->map(function (mixed $result): ?array {
                if (! is_array($result)) {
                    return null;
                }
                $lat = data_get($result, 'geometry.location.lat');
                $lon = data_get($result, 'geometry.location.lng');
                if (! is_numeric($lat) || ! is_numeric($lon)) {
                    return null;
                }

                return [
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                    'formatted_address' => data_get($result, 'formatted_address'),
                    'postal_code' => $this->postalCodeFromAddressComponents(
                        (array) data_get($result, 'address_components', []),
                    ),
                ];
            })
            ->filter()
            ->unique(fn (array $candidate): string => $candidate['lat'].'|'.$candidate['lon'].'|'.($candidate['formatted_address'] ?? ''))
            ->values();
        if ($candidates->count() !== 1) {
            return [
                'origin' => null,
                'error_code' => $candidates->count() > 1
                    ? 'places_location_ambiguous'
                    : 'places_location_not_found',
                'candidates' => $candidates->take(5)->all(),
            ];
        }

        return [
            'origin' => $candidates->first(),
            'error_code' => null,
            'candidates' => [],
        ];
    }

    private function googleLocationAddress(string $locationQuery): string
    {
        if ($this->postalCodeFromLocation($locationQuery) !== null) {
            return "{$locationQuery}, USA";
        }

        return $locationQuery;
    }

    /** @return array{origin:?array,error_code:?string,candidates:list<array<string,mixed>>} */
    private function osmLocationOrigin(string $locationQuery, ?Carbon $hardDeadlineAt): array
    {
        $postalCode = $this->postalCodeFromLocation($locationQuery);
        if ($postalCode !== null) {
            $zipOrigin = $this->zippopotamOrigin($postalCode, $hardDeadlineAt);
            if ($zipOrigin !== null) {
                return ['origin' => $zipOrigin, 'error_code' => null, 'candidates' => []];
            }
        }

        try {
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return ['origin' => null, 'error_code' => 'places_location_not_found', 'candidates' => []];
            }
            $timeouts = $this->httpTimeouts(
                (float) config('services.hermes_runtime.osm_places_connect_timeout', 2),
                (float) config('services.hermes_runtime.osm_places_timeout', 5),
                $hardDeadlineAt,
            );
            $response = Http::acceptJson()
                ->withUserAgent('HeyBean/1.0')
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get(rtrim((string) config('services.hermes_runtime.osm_photon_base', 'https://photon.komoot.io'), '/').'/api/', [
                    'q' => $locationQuery,
                    'limit' => 5,
                    'lang' => 'en',
                ]);
        } catch (ConnectionException) {
            return ['origin' => null, 'error_code' => 'places_location_not_found', 'candidates' => []];
        }

        if (! $response->successful()) {
            return ['origin' => null, 'error_code' => 'places_location_not_found', 'candidates' => []];
        }

        $candidates = collect((array) data_get($response->json(), 'features'))
            ->map(function (mixed $feature) use ($locationQuery): ?array {
                if (! is_array($feature)) {
                    return null;
                }
                $coordinates = (array) data_get($feature, 'geometry.coordinates', []);
                $lon = $coordinates[0] ?? null;
                $lat = $coordinates[1] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lon)) {
                    return null;
                }
                $properties = (array) data_get($feature, 'properties', []);
                $formattedAddress = trim(implode(', ', array_unique(array_filter([
                    data_get($properties, 'name'),
                    data_get($properties, 'city'),
                    data_get($properties, 'state'),
                    data_get($properties, 'country'),
                ]))));

                return [
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                    'formatted_address' => $formattedAddress !== '' ? $formattedAddress : $locationQuery,
                    'postal_code' => (string) data_get($properties, 'postcode', ''),
                ];
            })
            ->filter()
            ->unique(fn (array $candidate): string => $candidate['lat'].'|'.$candidate['lon'].'|'.$candidate['formatted_address'])
            ->values();
        if ($candidates->count() !== 1) {
            return [
                'origin' => null,
                'error_code' => $candidates->count() > 1
                    ? 'places_location_ambiguous'
                    : 'places_location_not_found',
                'candidates' => $candidates->take(5)->all(),
            ];
        }

        return [
            'origin' => $candidates->first(),
            'error_code' => null,
            'candidates' => [],
        ];
    }

    private function zippopotamOrigin(string $postalCode, ?Carbon $hardDeadlineAt): ?array
    {
        try {
            if ($this->deadlineExpired($hardDeadlineAt)) {
                return null;
            }
            $timeouts = $this->httpTimeouts(
                (float) config('services.hermes_runtime.osm_places_connect_timeout', 2),
                (float) config('services.hermes_runtime.osm_places_timeout', 5),
                $hardDeadlineAt,
            );
            $response = Http::acceptJson()
                ->connectTimeout($timeouts['connect'])
                ->timeout($timeouts['total'])
                ->get(rtrim((string) config('services.hermes_runtime.zippopotam_base', 'https://api.zippopotam.us'), '/').'/us/'.$postalCode);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $place = collect((array) data_get($response->json(), 'places'))->first();
        $lat = data_get($place, 'latitude');
        $lon = data_get($place, 'longitude');
        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
            'formatted_address' => trim(implode(', ', array_filter([
                $postalCode,
                data_get($place, 'place name'),
                data_get($place, 'state abbreviation'),
            ]))),
            'postal_code' => $postalCode,
        ];
    }

    private function normalizeGooglePlace(array $place, array $origin, ?string $requestedPostalCode): array
    {
        $name = trim((string) data_get($place, 'displayName.text', ''));
        $address = trim((string) data_get($place, 'formattedAddress', ''));
        $lat = data_get($place, 'location.latitude');
        $lon = data_get($place, 'location.longitude');
        $distanceMeters = is_numeric($lat) && is_numeric($lon)
            ? $this->distanceMeters((float) $origin['lat'], (float) $origin['lon'], (float) $lat, (float) $lon)
            : null;

        return [
            'name' => $name,
            'address' => $address !== '' ? $address : null,
            'distance_meters' => $distanceMeters,
            'distance_miles' => $distanceMeters !== null ? round($distanceMeters / 1609.344, 1) : null,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lon' => is_numeric($lon) ? (float) $lon : null,
            'categories' => array_values((array) data_get($place, 'types', [])),
            'place_id' => data_get($place, 'id'),
            'google_maps_url' => data_get($place, 'googleMapsUri'),
            'business_status' => data_get($place, 'businessStatus'),
            'postal_code_match' => $requestedPostalCode !== null && str_contains($address, $requestedPostalCode),
        ];
    }

    private function normalizeOsmPlace(array $feature, array $origin, ?string $requestedPostalCode): array
    {
        $properties = (array) data_get($feature, 'properties', []);
        $coordinates = (array) data_get($feature, 'geometry.coordinates', []);
        $lon = $coordinates[0] ?? null;
        $lat = $coordinates[1] ?? null;
        $address = $this->osmPlaceAddress($properties);
        $distanceMeters = is_numeric($lat) && is_numeric($lon)
            ? $this->distanceMeters((float) $origin['lat'], (float) $origin['lon'], (float) $lat, (float) $lon)
            : null;
        $osmType = (string) ($properties['osm_type'] ?? '');
        $osmId = (string) ($properties['osm_id'] ?? '');

        return [
            'name' => trim((string) ($properties['name'] ?? '')),
            'address' => $address !== '' ? $address : null,
            'distance_meters' => $distanceMeters,
            'distance_miles' => $distanceMeters !== null ? round($distanceMeters / 1609.344, 1) : null,
            'lat' => is_numeric($lat) ? (float) $lat : null,
            'lon' => is_numeric($lon) ? (float) $lon : null,
            'categories' => array_values(array_filter([
                $properties['osm_key'] ?? null,
                $properties['osm_value'] ?? null,
                $properties['type'] ?? null,
            ])),
            'place_id' => $osmType !== '' && $osmId !== '' ? "{$osmType}:{$osmId}" : null,
            'google_maps_url' => is_numeric($lat) && is_numeric($lon) ? 'https://www.openstreetmap.org/?mlat='.$lat.'&mlon='.$lon.'#map=18/'.$lat.'/'.$lon : null,
            'business_status' => null,
            'postal_code_match' => $requestedPostalCode !== null && (string) ($properties['postcode'] ?? '') === $requestedPostalCode,
        ];
    }

    private function osmPlaceAddress(array $properties): string
    {
        $street = trim(implode(' ', array_filter([
            $properties['housenumber'] ?? null,
            $properties['street'] ?? null,
        ])));

        return trim(implode(', ', array_filter([
            $street !== '' ? $street : null,
            $properties['city'] ?? $properties['locality'] ?? null,
            $properties['state'] ?? null,
            $properties['postcode'] ?? null,
            $properties['country'] ?? null,
        ])));
    }

    private function placeMatchesSearchName(array $place, string $placeName): bool
    {
        return $this->placeNameMatchScore($place, $placeName) > 0;
    }

    private function placeNameMatchScore(array $place, string $placeName): int
    {
        $needle = mb_strtolower($placeName);
        if ($needle === '') {
            return 1;
        }

        $name = mb_strtolower((string) ($place['name'] ?? ''));
        $address = mb_strtolower((string) ($place['address'] ?? ''));
        $normalizedNeedle = $this->normalizedPlaceName($needle);
        $normalizedName = $this->normalizedPlaceName($name);

        if ($normalizedName === $normalizedNeedle) {
            return 100;
        }

        if (str_starts_with($normalizedName, $normalizedNeedle.' ')) {
            return 70;
        }

        if ((bool) preg_match('/(^|\s)'.preg_quote($normalizedNeedle, '/').'($|\s)/u', $normalizedName)) {
            return 50;
        }

        if (str_contains($normalizedName, $normalizedNeedle)) {
            return 30;
        }

        if (str_contains($address, $needle)) {
            return 10;
        }

        return 0;
    }

    private function placeRankingDistanceMeters(array $place): int
    {
        $distance = $place['distance_meters'] ?? null;
        $score = (int) ($place['name_match_score'] ?? 0);
        $penalty = match (true) {
            $score >= 100 => 0,
            $score >= 70 => 500,
            $score >= 50 => 1000,
            $score >= 30 => 3000,
            default => 10000,
        };

        return (is_numeric($distance) ? (int) $distance : 99_999_999) + $penalty;
    }

    private function normalizedPlaceName(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower($value)) ?? $value;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private function postalCodeFromLocation(string $locationQuery): ?string
    {
        if (preg_match('/\b\d{5}(?:-\d{4})?\b/', $locationQuery, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function postalCodeFromAddressComponents(array $components): ?string
    {
        foreach ($components as $component) {
            if (in_array('postal_code', (array) data_get($component, 'types', []), true)) {
                return (string) data_get($component, 'long_name');
            }
        }

        return null;
    }

    private function distanceMeters(float $fromLat, float $fromLon, float $toLat, float $toLon): int
    {
        $earthRadiusMeters = 6371000;
        $fromLatRad = deg2rad($fromLat);
        $toLatRad = deg2rad($toLat);
        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLon = deg2rad($toLon - $fromLon);
        $a = sin($deltaLat / 2) ** 2
            + cos($fromLatRad) * cos($toLatRad) * sin($deltaLon / 2) ** 2;

        return (int) round($earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function placesText(array $places, string $placeName, string $locationQuery): string
    {
        $first = $places[0];
        $distance = isset($first['distance_miles']) ? " about {$first['distance_miles']} miles away" : '';
        $address = $first['address'] ? " at {$first['address']}" : '';
        $lines = ["The nearest {$placeName} I found near {$locationQuery} is {$first['name']}{$address}{$distance}."];

        if (count($places) > 1) {
            $lines[] = 'Other close matches:';
            foreach (array_slice($places, 1, 4) as $place) {
                $placeDistance = isset($place['distance_miles']) ? " ({$place['distance_miles']} mi)" : '';
                $placeAddress = $place['address'] ? " - {$place['address']}" : '';
                $lines[] = "- {$place['name']}{$placeDistance}{$placeAddress}";
            }
        }

        return implode("\n", $lines);
    }

    private function tavilyText(array $response): string
    {
        $answer = trim((string) ($response['answer'] ?? ''));
        if ($answer !== '') {
            return $answer;
        }

        $snippets = collect((array) ($response['results'] ?? []))
            ->map(fn ($result): string => trim((string) data_get($result, 'content', '')))
            ->filter()
            ->take(3)
            ->values()
            ->all();

        return trim(implode("\n\n", $snippets));
    }

    private function tavilySources(array $response): array
    {
        return collect((array) ($response['results'] ?? []))
            ->map(function ($result): array {
                return [
                    'title' => trim((string) data_get($result, 'title', '')) ?: null,
                    'url' => trim((string) data_get($result, 'url', '')),
                ];
            })
            ->filter(fn (array $source): bool => $source['url'] !== '')
            ->unique('url')
            ->take(8)
            ->values()
            ->all();
    }

    private function webSearchFailure(User $user, ConversationSession $session, string $query, string $toolType, string $errorCode, int $latencyMs, ?int $status = null): array
    {
        $metadata = [
            'conversation_session_id' => $session->id,
            'query' => $query,
            'tool_type' => $toolType,
            'live_lookup_provider' => 'openai_web_search',
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
        ];
        if ($status !== null) {
            $metadata['status'] = $status;
        }
        $this->usageService->recordDirectCall($user, $session->workspace_id, 'web_search', $this->adminSettings->externalLookupModel(), [], $metadata, ['external_lookup', 'web_search'], 'failed');

        $result = [
            'ok' => false,
            'tool' => 'external_lookup',
            'provider' => 'openai_web_search',
            'error_code' => $errorCode,
            'latency_ms' => $latencyMs,
        ];
        if ($status !== null) {
            $result['status'] = $status;
        }

        return $result;
    }

    private function externalLookupPrompt(
        ConversationSession $session,
        string $query,
        string $context,
        string $location,
        ?string $trustedTimezone,
    ): string {
        $user = User::find($session->user_id);
        $timezone = $this->validTrustedTimezone($trustedTimezone);
        $parts = [
            "Lookup query: {$query}",
            'Current server time (UTC): '.now('UTC')->toIso8601String(),
            'User timezone: '.($timezone ?? 'unknown'),
        ];
        if ($location !== '') {
            $parts[] = "User/location hint: {$location}";
        }
        if ($context !== '') {
            $parts[] = "Additional context: {$context}";
        }
        if ($user?->name) {
            $parts[] = "User name: {$user->name}";
        }

        return implode("\n", $parts);
    }

    private function providerUsage(mixed $since): array
    {
        return AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->where(function ($query): void {
                $query->where('request_type', 'external_lookup')
                    ->orWhere('request_type', 'web_search')
                    ->orWhereJsonContains('action_types', 'external_lookup')
                    ->orWhereJsonContains('action_types', 'web_search')
                    ->orWhereJsonContains('action_types', 'open_meteo_weather');
            })
            ->get()
            ->groupBy(fn (AiUsageLog $log): string => $this->providerKeyForLog($log))
            ->map(fn ($logs): array => [
                'requests' => $logs->count(),
                'completed' => $logs->where('status', 'completed')->count(),
                'failed' => $logs->where('status', 'failed')->count(),
                'blocked' => $logs->where('status', 'blocked')->count(),
                'cost' => round((float) $logs->sum('estimated_cost_usd'), 4),
                'avg_latency_ms' => $this->averageLatencyMs($logs),
                'last_used_at' => $logs->sortByDesc('created_at')->first()?->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function providerKeyForLog(AiUsageLog $log): string
    {
        $provider = (string) data_get($log->metadata ?? [], 'live_lookup_provider', '');
        if ($provider !== '') {
            return $provider;
        }

        if (collect($log->action_types ?? [])->contains('open_meteo_weather') || $log->provider === 'open_meteo') {
            return 'open_meteo';
        }

        if (collect($log->action_types ?? [])->contains('web_search') || $log->request_type === 'web_search') {
            return 'openai_web_search';
        }

        return $log->provider ?: 'unknown';
    }

    private function averageLatencyMs($logs): ?int
    {
        $values = $logs
            ->map(fn (AiUsageLog $log): int => (int) data_get($log->metadata ?? [], 'latency_ms', 0))
            ->filter(fn (int $value): bool => $value > 0);

        return $values->isEmpty() ? null : (int) round($values->avg());
    }

    private function cacheKey(string $query, string $context, string $location, array $arguments = []): string
    {
        $structured = collect($arguments)
            ->only([
                'kind', 'domain', 'intent', 'date', 'date_range', 'time', 'topic',
                'latitude', 'longitude', 'units',
            ])
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->all();

        return 'live_lookup:'.sha1(mb_strtolower(trim($query.'|'.$context.'|'.$location.'|'.json_encode($structured))));
    }

    private function validTrustedTimezone(?string $timezone): ?string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') {
            return null;
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }

    private function providerApiKey(): string
    {
        return (string) config('services.hermes_runtime.api_key', '');
    }

    private function tavilyApiKey(): string
    {
        return (string) config('services.hermes_runtime.tavily_api_key', '');
    }

    private function googleMapsApiKey(): string
    {
        return (string) config('services.hermes_runtime.google_maps_api_key', '');
    }

    private function extractResponseText(array $response): string
    {
        $outputText = trim((string) data_get($response, 'output_text', ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $segments = [];
        foreach ((array) data_get($response, 'output', []) as $item) {
            foreach ((array) data_get($item, 'content', []) as $content) {
                $text = trim((string) data_get($content, 'text', ''));
                if ($text !== '') {
                    $segments[] = $text;
                }
            }
        }

        return trim(implode("\n\n", $segments));
    }

    private function extractResponseCitations(array $response): array
    {
        $citations = [];
        $this->collectUrlReferences($response, $citations, true);

        return collect($citations)
            ->unique('url')
            ->take(8)
            ->values()
            ->all();
    }

    private function extractResponseSources(array $response): array
    {
        $sources = [];
        foreach ((array) data_get($response, 'output', []) as $item) {
            foreach ((array) data_get($item, 'action.sources', []) as $source) {
                if (is_array($source)) {
                    $url = trim((string) ($source['url'] ?? ''));
                    if ($url !== '') {
                        $sources[] = [
                            'url' => $url,
                            'title' => trim((string) ($source['title'] ?? '')) ?: null,
                        ];
                    }
                }
            }
        }
        $this->collectUrlReferences($response, $sources, false);

        return collect($sources)
            ->unique('url')
            ->take(12)
            ->values()
            ->all();
    }

    private function collectUrlReferences(mixed $value, array &$references, bool $citationsOnly): void
    {
        if (! is_array($value)) {
            return;
        }

        $type = (string) ($value['type'] ?? '');
        $url = trim((string) ($value['url'] ?? ''));
        if ($url !== '' && (! $citationsOnly || $type === 'url_citation')) {
            $references[] = [
                'url' => $url,
                'title' => trim((string) ($value['title'] ?? '')) ?: null,
            ];
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectUrlReferences($child, $references, $citationsOnly);
            }
        }
    }
}
