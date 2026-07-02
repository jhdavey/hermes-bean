<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\AiUsageLog;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
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

    public function lookup(ConversationSession $session, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'tool' => 'external_lookup', 'error_code' => 'missing_query', 'message' => 'A lookup query is required.'];
        }

        $startedAt = microtime(true);
        $user = User::findOrFail($session->user_id);
        $context = trim((string) ($arguments['context'] ?? ''));
        $location = trim((string) ($arguments['location'] ?? ''));
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

            return ['ok' => false, 'tool' => 'external_lookup', 'provider' => 'budget_guard', 'error_code' => 'external_lookup_limit', 'message' => $externalPreflight['reason']];
        }

        if ((bool) config('services.hermes_runtime.weather_lookup_enabled', true)) {
            $timezone = $this->sessionTimezone($session);
            $weatherResult = $this->weatherService->weatherForIntent($arguments, $timezone, [
                'source' => 'live_lookup_gateway',
                'session_id' => $session->id,
                'workspace_id' => $session->workspace_id,
                'timezone' => $timezone,
            ]);
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

                if (($result['error_code'] ?? null) === 'weather_location_missing') {
                    return $result;
                }
            }
        }

        if ($this->shouldUsePlaceLookup($query, $context, $location)) {
            $placesResult = $this->googlePlacesLookup($session, $user, $query, $context, $location, $startedAt, $cacheKey);
            if ($this->shouldReturnProviderResult($placesResult)) {
                return $placesResult;
            }
        }

        $tavilyResult = $this->tavilySearchLookup($session, $user, $query, $context, $location, $startedAt, $cacheKey);
        if ($this->shouldReturnProviderResult($tavilyResult)) {
            return $tavilyResult;
        }

        return $this->webSearchLookup($session, $user, $query, $context, $location, $startedAt, $cacheKey);
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
                'notes' => 'Used for current weather and forecast-style requests before web search.',
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

    private function googlePlacesLookup(ConversationSession $session, User $user, string $query, string $context, string $location, float $startedAt, string $cacheKey): ?array
    {
        if (! (bool) config('services.hermes_runtime.google_places_enabled', true) || $this->googleMapsApiKey() === '') {
            return null;
        }

        $placeName = $this->placeSearchName($query);
        $locationQuery = $this->placeLocationQuery($query, $location);
        if ($placeName === '' || $locationQuery === '') {
            return null;
        }

        try {
            $origin = $this->googleGeocodeLocation($locationQuery);
            if ($origin === null) {
                return $this->providerFailure($user, $session, 'google_places', 'google-geocoding', $query, 'places_location_not_found', 'Google could not identify that location.', $startedAt, null, ['stage' => 'geocode']);
            }

            $radius = max(1000, (int) config('services.hermes_runtime.google_places_radius_meters', 50000));
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsApiKey(),
                    'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.googleMapsUri,places.businessStatus,places.types',
                ])
                ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => trim("{$placeName} {$locationQuery}"),
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

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            if (! $response->successful()) {
                return $this->providerFailure($user, $session, 'google_places', 'google-places-text-search', $query, 'places_lookup_failed', 'Google Places could not return place results.', $startedAt, $response->status(), ['stage' => 'text_search']);
            }

            $requestedPostalCode = $this->postalCodeFromLocation($locationQuery);
            $places = collect((array) data_get($response->json(), 'places'))
                ->map(function ($place) use ($origin, $requestedPostalCode, $placeName): array {
                    $normalized = $this->normalizeGooglePlace(is_array($place) ? $place : [], $origin, $requestedPostalCode);
                    $normalized['name_match_score'] = $this->placeNameMatchScore($normalized, $placeName);

                    return $normalized;
                })
                ->filter(fn (array $place): bool => ($place['name'] ?? '') !== '')
                ->filter(fn (array $place): bool => (int) ($place['name_match_score'] ?? 0) > 0)
                ->sortBy([
                    ['name_match_score', 'desc'],
                    ['postal_code_match', 'desc'],
                    ['distance_meters', 'asc'],
                ])
                ->take(5)
                ->values()
                ->all();

            if ($places === []) {
                return $this->providerFailure($user, $session, 'google_places', 'google-places-text-search', $query, 'places_not_found', 'Google Places did not find a matching place nearby.', $startedAt, null, ['stage' => 'text_search', 'location_query' => $locationQuery]);
            }

            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'google-places', [
                'tool_call_count' => 2,
            ], [
                'conversation_session_id' => $session->id,
                'provider' => 'google',
                'live_lookup_provider' => 'google_places',
                'query' => $query,
                'place_name' => $placeName,
                'location_query' => $locationQuery,
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

            return $this->providerFailure($user, $session, 'google_places', 'google-places', $query, 'places_lookup_timeout', 'The places lookup timed out before it could return local information.', $startedAt);
        }
    }

    private function tavilySearchLookup(ConversationSession $session, User $user, string $query, string $context, string $location, float $startedAt, string $cacheKey): ?array
    {
        if (! (bool) config('services.hermes_runtime.tavily_search_enabled', true) || $this->tavilyApiKey() === '') {
            return null;
        }

        $searchQuery = trim(implode(' ', array_filter([$query, $location !== '' ? "near {$location}" : null])));

        try {
            $response = Http::withToken($this->tavilyApiKey())
                ->acceptJson()
                ->asJson()
                ->connectTimeout((float) config('services.hermes_runtime.tavily_search_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.tavily_search_timeout', 6))
                ->post('https://api.tavily.com/search', [
                    'query' => $searchQuery,
                    'search_depth' => (string) config('services.hermes_runtime.tavily_search_depth', 'ultra-fast'),
                    'topic' => $this->tavilyTopic($query),
                    'include_answer' => 'basic',
                    'include_raw_content' => false,
                    'include_images' => false,
                    'include_favicon' => true,
                    'include_usage' => true,
                    'max_results' => 5,
                ]);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            if (! $response->successful()) {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_failed', 'Tavily search failed.', $startedAt, $response->status());
            }

            $decoded = $response->json();
            if (! is_array($decoded)) {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_non_json', 'Tavily returned an unreadable response.', $startedAt);
            }

            $text = $this->tavilyText($decoded);
            if ($text === '') {
                return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_empty', 'Tavily did not return an answer.', $startedAt);
            }

            $credits = (float) data_get($decoded, 'usage.credits', 0);
            $this->usageService->recordDirectCall($user, $session->workspace_id, 'external_lookup', 'tavily-search', [
                'tool_call_count' => 1,
            ], [
                'conversation_session_id' => $session->id,
                'provider' => 'tavily',
                'live_lookup_provider' => 'tavily_search',
                'query' => $query,
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

            return $this->providerFailure($user, $session, 'tavily_search', 'tavily-search', $query, 'tavily_lookup_timeout', 'The web lookup timed out before it could return current information.', $startedAt);
        }
    }

    private function webSearchLookup(ConversationSession $session, User $user, string $query, string $context, string $location, float $startedAt, string $cacheKey): array
    {
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

            return ['ok' => false, 'tool' => 'external_lookup', 'provider' => 'openai_web_search', 'error_code' => 'web_search_limit', 'message' => $webSearchPreflight['reason']];
        }

        $payload = [
            'model' => $this->adminSettings->externalLookupModel(),
            'tools' => [
                ['type' => $toolType],
            ],
            'tool_choice' => 'auto',
            'instructions' => 'You are a concise live lookup helper for Bean. Search the web when needed, answer only from current external results, and include citations in the response annotations when available. If results are incomplete or uncertain, say so plainly.',
            'input' => $this->externalLookupPrompt($session, $query, $context, $location),
        ];

        $attempts = max(1, (int) config('services.hermes_runtime.external_lookup_attempts', 1));
        $response = null;
        $lastException = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withToken($this->providerApiKey())
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout((float) config('services.hermes_runtime.external_lookup_connect_timeout', 3))
                    ->timeout((float) config('services.hermes_runtime.external_lookup_timeout', 8))
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
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_timeout', 'The live lookup timed out before it could return current information.', $latencyMs);
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

            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_failed', 'The external lookup failed.', $latencyMs, $response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_non_json', 'The external lookup returned an unreadable response.', $latencyMs);
        }

        $text = $this->extractResponseText($decoded);
        if ($text === '') {
            return $this->webSearchFailure($user, $session, $query, $toolType, 'external_lookup_empty', 'The external lookup did not return an answer.', $latencyMs);
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

    private function providerFailure(User $user, ConversationSession $session, string $providerKey, string $model, string $query, string $errorCode, string $message, float $startedAt, ?int $status = null, array $metadata = []): array
    {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $userMessage = $this->lookupContinuationMessage($query);
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
            'message' => $userMessage,
            'diagnostic_message' => $message,
            'latency_ms' => $latencyMs,
            'fallback_allowed' => true,
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

    private function shouldUsePlaceLookup(string $query, string $context, string $location): bool
    {
        if ($location !== '') {
            if ((bool) preg_match('/\b(nearest|closest|nearby|near me|near|around|local|store|restaurant|coffee|gas|pharmacy|address|location|hours|open|closed)\b/i', $query.' '.$context)) {
                return true;
            }

            $placeName = $this->placeSearchName($query);
            $wordCount = str_word_count($placeName);

            return $placeName !== ''
                && $wordCount > 0
                && $wordCount <= 4
                && ! (bool) preg_match('/\b(news|latest|today|stock|stocks|market|earnings|weather|score|price|headline)\b/i', $query.' '.$context);
        }

        return (bool) preg_match('/\b(nearest|closest|nearby|near me|near \d{5}|around \d{5}|to \d{5}|in \d{5}|local)\b/i', $query.' '.$context);
    }

    private function placeSearchName(string $query): string
    {
        if (preg_match('/\b(?:find\s+)?(?:the\s+)?(?:nearest|closest|nearby)\s+([a-z0-9&.\'\s-]+?)\s+(?:to|near|around|in)\b/i', $query, $matches)) {
            $candidate = $this->cleanPlaceNameCandidate((string) ($matches[1] ?? ''));
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 80);
            }
        }

        $clean = mb_strtolower($query);
        $clean = preg_replace('/\b(what|where|which|who|can you|could you|please|find|look up|search for|tell me|return|is|are|the|a|an|and|full|street|zip|code)\b/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b(nearest|closest|nearby|near me|near|around|to me|from me|local|location|locations|address|addresses|hours|open|closed|right now|today)\b/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b(in|at|by|to)\s+[a-z\s,.-]*\d{5}(?:-\d{4})?\b/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d{5}(?:-\d{4})?\b/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[^a-z0-9&.\'\s-]/i', ' ', $clean) ?? $clean;
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        return mb_substr($this->stripGenericPlaceNameWords($clean), 0, 80);
    }

    private function cleanPlaceNameCandidate(string $candidate): string
    {
        $candidate = preg_replace('/\b(the|a|an)\b/i', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/[^a-z0-9&.\'\s-]/i', ' ', $candidate) ?? $candidate;

        return $this->stripGenericPlaceNameWords(trim((string) preg_replace('/\s+/', ' ', $candidate)));
    }

    private function stripGenericPlaceNameWords(string $candidate): string
    {
        $candidate = trim((string) preg_replace('/\s+/', ' ', $candidate));
        if ($candidate === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $candidate) ?: [];
        if (count($tokens) <= 1) {
            return $candidate;
        }

        $generic = [
            'address',
            'addresses',
            'business',
            'businesses',
            'location',
            'locations',
            'place',
            'places',
            'shop',
            'shops',
            'store',
            'stores',
        ];
        $filtered = array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! in_array(mb_strtolower($token), $generic, true),
        ));

        return $filtered !== [] ? implode(' ', $filtered) : $candidate;
    }

    private function placeLocationQuery(string $query, string $location): string
    {
        if ($location !== '') {
            return $location;
        }

        if (preg_match('/\b\d{5}(?:-\d{4})?\b/', $query, $matches)) {
            return $matches[0];
        }

        if (preg_match('/\b(?:near|around|in)\s+([a-z][a-z\s,.-]+)$/i', $query, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function googleGeocodeLocation(string $locationQuery): ?array
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

        $response = Http::acceptJson()
            ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
            ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
            ->get('https://maps.googleapis.com/maps/api/geocode/json', $params);

        if (! $response->successful() || data_get($response->json(), 'status') !== 'OK') {
            return null;
        }

        $result = collect((array) data_get($response->json(), 'results'))->first();
        $lat = data_get($result, 'geometry.location.lat');
        $lon = data_get($result, 'geometry.location.lng');
        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
            'formatted_address' => data_get($result, 'formatted_address'),
            'postal_code' => $this->postalCodeFromAddressComponents((array) data_get($result, 'address_components', [])),
        ];
    }

    private function googleLocationAddress(string $locationQuery): string
    {
        if ($this->postalCodeFromLocation($locationQuery) !== null) {
            return "{$locationQuery}, USA";
        }

        return $locationQuery;
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

    private function tavilyTopic(string $query): string
    {
        if ((bool) preg_match('/\b(stock|stocks|market|earnings|revenue|share price|crypto|bitcoin|nasdaq|dow|s&p)\b/i', $query)) {
            return 'finance';
        }

        if ((bool) preg_match('/\b(news|latest|today|breaking|election|sports|score|happened)\b/i', $query)) {
            return 'news';
        }

        return 'general';
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

    private function webSearchFailure(User $user, ConversationSession $session, string $query, string $toolType, string $errorCode, string $message, int $latencyMs, ?int $status = null): array
    {
        $userMessage = $this->lookupContinuationMessage($query);
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
            'message' => $userMessage,
            'diagnostic_message' => $message,
            'latency_ms' => $latencyMs,
        ];
        if ($status !== null) {
            $result['status'] = $status;
        }

        return $result;
    }

    private function lookupContinuationMessage(string $query): string
    {
        $topic = str($query)->squish()->limit(80, '')->toString();

        return $topic === ''
            ? 'I’m still checking live sources for that. Send me one more detail if you want me to narrow it down further.'
            : "I’m still checking live sources for {$topic}. Send me one more detail if you want me to narrow it down further.";
    }

    private function externalLookupPrompt(ConversationSession $session, string $query, string $context, string $location): string
    {
        $user = User::find($session->user_id);
        $profile = $this->profileForSession($session);
        $profileSettings = $profile?->settings ?? [];
        $timezone = (string) data_get($profileSettings, 'timezone', config('app.timezone'));
        $now = now($timezone);
        $parts = [
            "Lookup query: {$query}",
            'Current date/time: '.$now->toIso8601String(),
            'Timezone: '.$timezone,
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
            ->only(['domain', 'intent', 'date', 'date_range', 'time'])
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->all();

        return 'live_lookup:'.sha1(mb_strtolower(trim($query.'|'.$context.'|'.$location.'|'.json_encode($structured))));
    }

    private function sessionTimezone(ConversationSession $session): string
    {
        $profileSettings = $this->profileForSession($session)?->settings ?? [];
        $timezone = (string) data_get($profileSettings, 'timezone', config('app.timezone'));

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return (string) config('app.timezone');
        }
    }

    private function profileForSession(ConversationSession $session): ?AgentProfile
    {
        if ($session->workspace_id) {
            return AgentProfile::where('workspace_id', $session->workspace_id)->first();
        }

        return AgentProfile::where('user_id', $session->user_id)->first();
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
