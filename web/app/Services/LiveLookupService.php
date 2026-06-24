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
        $cacheKey = $this->cacheKey($query, $context, $location);
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
            $weatherResult = $this->weatherService->currentWeatherForQuery($query, $context, $location, [
                'source' => 'live_lookup_gateway',
                'session_id' => $session->id,
                'workspace_id' => $session->workspace_id,
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
                }

                return $result;
            }
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

    private function webSearchFailure(User $user, ConversationSession $session, string $query, string $toolType, string $errorCode, string $message, int $latencyMs, ?int $status = null): array
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
            'message' => $message,
            'latency_ms' => $latencyMs,
        ];
        if ($status !== null) {
            $result['status'] = $status;
        }

        return $result;
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

    private function cacheKey(string $query, string $context, string $location): string
    {
        return 'live_lookup:'.sha1(mb_strtolower(trim($query.'|'.$context.'|'.$location)));
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
