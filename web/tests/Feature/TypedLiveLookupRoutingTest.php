<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\AiUsageService;
use App\Services\LiveLookupService;
use App\Services\OpenMeteoWeatherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TypedLiveLookupRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.hermes_runtime.weather_lookup_enabled', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_typed_weather_uses_only_the_explicit_provider_route(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed lookup',
            'status' => 'active',
            'metadata' => ['client_context' => ['timezone' => 'America/New_York']],
        ]);

        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(20);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->once();

        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');

        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldReceive('weatherForStructuredIntent')
            ->once()
            ->withArgs(function (array $arguments, string $timezone): bool {
                return $arguments['kind'] === 'forecast'
                    && $arguments['location'] === 'Orlando, Florida'
                    && $arguments['date'] === '2026-07-15'
                    && $arguments['units'] === 'imperial'
                    && $timezone !== '';
            })
            ->andReturn([
                'ok' => true,
                'tool' => 'external_lookup',
                'kind' => 'weather_forecast',
                'text' => 'Receipt-backed forecast.',
            ]);

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'What will it be like there tomorrow?',
            'kind' => 'forecast',
            'location' => 'Orlando, Florida',
            'date' => '2026-07-15',
            'units' => 'imperial',
        ], null, 'America/New_York');

        $this->assertTrue($result['ok']);
        $this->assertSame('open_meteo', $result['provider']);
        $this->assertSame('Receipt-backed forecast.', $result['text']);
    }

    public function test_structured_weather_never_extracts_place_or_time_from_query_prose(): void
    {
        $service = app(OpenMeteoWeatherService::class);

        $result = $service->weatherForStructuredIntent([
            'query' => 'Weather in Orlando tomorrow at 5 PM',
            'kind' => 'forecast',
            'date' => '2026-07-15',
            'units' => 'imperial',
        ], 'America/New_York');

        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
        $this->assertSame('weather_location_missing', $result['error_code']);
        $this->assertMachineReadableFailureReceipt($result);
    }

    public function test_typed_lookup_validation_receipts_keep_only_structured_constraints(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed lookup validation',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldNotReceive('estimateTokens');
        $usage->shouldNotReceive('preflightDirect');
        $usage->shouldNotReceive('recordDirectCall');
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldNotReceive('externalLookupModel');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForStructuredIntent');
        $service = new LiveLookupService($usage, $settings, $weather);

        $unsupported = $service->lookupTyped($session, [
            'query' => 'Current conditions',
            'kind' => 'weather',
            'units' => 'imperial',
            'legacy_domain' => 'weather',
        ]);
        $this->assertSame('typed_lookup_arguments_invalid', $unsupported['error_code']);
        $this->assertSame(['legacy_domain'], $unsupported['unsupported_fields']);
        $this->assertMachineReadableFailureReceipt($unsupported);

        $missingQuery = $service->lookupTyped($session, [
            'query' => '   ',
            'kind' => 'web',
            'topic' => 'general',
        ]);
        $this->assertSame('missing_query', $missingQuery['error_code']);
        $this->assertSame(['query'], $missingQuery['required_fields']);
        $this->assertMachineReadableFailureReceipt($missingQuery);
        Http::assertNothingSent();
    }

    public function test_external_lookup_budget_rejection_does_not_expose_the_application_reason_as_response_copy(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed lookup budget guard',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn([
            'allowed' => false,
            'reason' => 'This diagnostic-only plan explanation must not become receipt copy.',
        ]);
        $usage->shouldReceive('recordDirectCall')->once();
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->twice()->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForStructuredIntent');

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'The UPS Store',
            'kind' => 'places',
            'location' => 'Orlando, Florida',
        ]);

        $this->assertSame('external_lookup_limit', $result['error_code']);
        $this->assertSame('external_lookup', $result['limit_scope']);
        $this->assertTrue($result['blocked']);
        $this->assertArrayNotHasKey('reason', $result);
        $this->assertMachineReadableFailureReceipt($result);
    }

    public function test_web_search_budget_rejection_does_not_expose_the_application_reason_as_response_copy(): void
    {
        config()->set('services.hermes_runtime.tavily_search_enabled', false);
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed web budget guard',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->twice()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->twice()->andReturn(
            ['allowed' => true],
            [
                'allowed' => false,
                'reason' => 'This diagnostic-only web plan explanation must not become receipt copy.',
            ],
        );
        $usage->shouldReceive('recordDirectCall')->once();
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->times(3)->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForStructuredIntent');

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'What happened today?',
            'kind' => 'web',
            'topic' => 'news',
        ]);

        $this->assertSame('web_search_limit', $result['error_code']);
        $this->assertSame('web_search', $result['limit_scope']);
        $this->assertTrue($result['blocked']);
        $this->assertArrayNotHasKey('reason', $result);
        $this->assertMachineReadableFailureReceipt($result);
    }

    public function test_weather_disabled_and_deadline_receipts_keep_only_structured_flags(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed lookup guards',
            'status' => 'active',
        ]);
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForStructuredIntent');

        config()->set('services.hermes_runtime.weather_lookup_enabled', false);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldNotReceive('recordDirectCall');
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');
        $service = new LiveLookupService($usage, $settings, $weather);

        $disabled = $service->lookupTyped($session, [
            'query' => 'Current weather',
            'kind' => 'weather',
            'location' => 'Orlando, Florida',
            'units' => 'imperial',
        ]);
        $this->assertSame('weather_lookup_disabled', $disabled['error_code']);
        $this->assertFalse($disabled['weather_lookup_enabled']);
        $this->assertMachineReadableFailureReceipt($disabled);

        $deadlineUsage = Mockery::mock(AiUsageService::class);
        $deadlineUsage->shouldNotReceive('estimateTokens');
        $deadlineUsage->shouldNotReceive('preflightDirect');
        $deadlineUsage->shouldNotReceive('recordDirectCall');
        $deadlineSettings = Mockery::mock(AdminSettingsService::class);
        $deadlineSettings->shouldNotReceive('externalLookupModel');
        $deadline = (new LiveLookupService($deadlineUsage, $deadlineSettings, $weather))->lookupTyped(
            $session,
            [
                'query' => 'Current weather',
                'kind' => 'weather',
                'location' => 'Orlando, Florida',
                'units' => 'imperial',
            ],
            now()->subSecond(),
        );
        $this->assertSame('external_lookup_deadline', $deadline['error_code']);
        $this->assertTrue($deadline['deadline_reached']);
        $this->assertFalse($deadline['fallback_allowed']);
        $this->assertMachineReadableFailureReceipt($deadline);
    }

    public function test_structured_weather_uses_trusted_coordinates_without_reinterpreting_query_text(): void
    {
        Http::fake(fn ($request) => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            ? Http::response(['current' => [
                'time' => '2026-07-14T18:00',
                'temperature_2m' => 81,
                'weather_code' => 1,
            ]])
            : Http::response([], 500));

        $result = app(OpenMeteoWeatherService::class)->weatherForStructuredIntent([
            'query' => 'What is it like here?',
            'kind' => 'weather',
            'units' => 'imperial',
        ], 'America/New_York', [
            'location_label' => 'Home',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Home', $result['location']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && (float) $request['latitude'] === 28.5383
            && (float) $request['longitude'] === -81.3792);
    }

    public function test_ambiguous_weather_location_returns_candidates_for_hermes_without_guessing(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), 'geocoding-api.open-meteo.com')
            ? Http::response(['results' => [
                [
                    'name' => 'Springfield',
                    'admin1' => 'Illinois',
                    'country_code' => 'US',
                    'latitude' => 39.8017,
                    'longitude' => -89.6436,
                ],
                [
                    'name' => 'Springfield',
                    'admin1' => 'Missouri',
                    'country_code' => 'US',
                    'latitude' => 37.2089,
                    'longitude' => -93.2923,
                ],
            ]])
            : Http::response(['unexpected' => true], 500));

        $result = app(OpenMeteoWeatherService::class)->weatherForStructuredIntent([
            'query' => 'Return current conditions.',
            'kind' => 'weather',
            'location' => 'Springfield',
            'units' => 'imperial',
        ], 'America/New_York');

        $this->assertFalse($result['ok']);
        $this->assertSame('weather_location_ambiguous', $result['error_code']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame('Springfield, Illinois, US', $result['candidates'][0]['name']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast'));
    }

    public function test_sequential_open_meteo_requests_recalculate_timeouts_from_the_remaining_hard_deadline(): void
    {
        $origin = Carbon::parse('2026-07-14 12:00:00.250000', 'America/New_York');
        Carbon::setTestNow($origin);
        $hardDeadlineAt = $origin->copy()->addMilliseconds(250);
        $observed = [];
        Http::fake(function ($request, array $options) use (&$observed, $origin) {
            $observed[] = [
                'url' => $request->url(),
                'connect_timeout' => (float) ($options['connect_timeout'] ?? 0),
                'timeout' => (float) ($options['timeout'] ?? 0),
            ];
            if (str_contains($request->url(), 'geocoding-api.open-meteo.com')) {
                Carbon::setTestNow($origin->copy()->addMilliseconds(100));

                return Http::response(['results' => [[
                    'name' => 'Orlando',
                    'admin1' => 'Florida',
                    'country_code' => 'US',
                    'latitude' => 28.5383,
                    'longitude' => -81.3792,
                ]]]);
            }

            return Http::response(['current' => [
                'time' => '2026-07-14T12:00',
                'temperature_2m' => 88,
                'weather_code' => 1,
            ]]);
        });

        $result = app(OpenMeteoWeatherService::class)->weatherForStructuredIntent([
            'query' => 'Return current weather.',
            'kind' => 'weather',
            'location' => 'Orlando, Florida',
            'units' => 'imperial',
        ], 'America/New_York', [], $hardDeadlineAt);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $observed);
        $this->assertGreaterThanOrEqual(0.24, $observed[0]['timeout']);
        $this->assertLessThanOrEqual(0.25, $observed[0]['timeout']);
        $this->assertGreaterThanOrEqual(0.14, $observed[1]['timeout']);
        $this->assertLessThanOrEqual(0.15, $observed[1]['timeout']);
        foreach ($observed as $request) {
            $this->assertGreaterThan(0, $request['connect_timeout']);
            $this->assertLessThanOrEqual($request['timeout'], $request['connect_timeout']);
        }
    }

    public function test_typed_weather_cache_identity_distinguishes_coordinates_and_units(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed weather cache identity',
            'status' => 'active',
        ]);

        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->times(3)->andReturn(12);
        $usage->shouldReceive('preflightDirect')->times(3)->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->times(3);

        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->times(3)->andReturn('typed-provider');

        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldReceive('weatherForStructuredIntent')
            ->times(3)
            ->andReturnUsing(function (array $arguments, string $timezone, array $context): array {
                $this->assertSame('', $timezone);
                $this->assertSame($arguments['latitude'], $context['latitude']);
                $this->assertSame($arguments['longitude'], $context['longitude']);
                $this->assertSame($arguments['units'], $context['units']);

                return [
                    'ok' => true,
                    'tool' => 'external_lookup',
                    'kind' => 'weather_current',
                    'text' => implode('|', [
                        (string) $context['latitude'],
                        (string) $context['longitude'],
                        $context['units'],
                    ]),
                    'cached' => false,
                ];
            });

        $service = new LiveLookupService($usage, $settings, $weather);
        $base = [
            'query' => 'What is the weather here?',
            'kind' => 'weather',
            'location' => 'Current location',
        ];
        $orlandoImperialArguments = [
            ...$base,
            'latitude' => 28.5383,
            'longitude' => -81.3792,
            'units' => 'imperial',
        ];

        $orlandoImperial = $service->lookupTyped($session, $orlandoImperialArguments);
        $miamiImperial = $service->lookupTyped($session, [
            ...$base,
            'latitude' => 25.7617,
            'longitude' => -80.1918,
            'units' => 'imperial',
        ]);
        $miamiMetric = $service->lookupTyped($session, [
            ...$base,
            'latitude' => 25.7617,
            'longitude' => -80.1918,
            'units' => 'metric',
        ]);
        $orlandoImperialCached = $service->lookupTyped($session, $orlandoImperialArguments);

        $this->assertSame('28.5383|-81.3792|imperial', $orlandoImperial['text']);
        $this->assertSame('25.7617|-80.1918|imperial', $miamiImperial['text']);
        $this->assertSame('25.7617|-80.1918|metric', $miamiMetric['text']);
        $this->assertSame($orlandoImperial['text'], $orlandoImperialCached['text']);
        $this->assertTrue($orlandoImperialCached['cached']);
    }

    public function test_structured_weather_propagates_metric_units_through_every_open_meteo_response_shape(): void
    {
        Http::fake(function ($request) {
            $payload = $request->data();
            if (data_get($payload, 'current') !== null) {
                return Http::response(['current' => [
                    'time' => '2026-07-14T18:00',
                    'temperature_2m' => 21.4,
                    'apparent_temperature' => 20.2,
                    'relative_humidity_2m' => 70,
                    'precipitation' => 1.2,
                    'weather_code' => 1,
                    'cloud_cover' => 20,
                    'wind_speed_10m' => 12.4,
                    'wind_direction_10m' => 90,
                    'wind_gusts_10m' => 18.6,
                ]]);
            }

            if (data_get($payload, 'hourly') !== null) {
                return Http::response(['hourly' => [
                    'time' => ['2026-07-15T17:00'],
                    'temperature_2m' => [23.4],
                    'apparent_temperature' => [24.1],
                    'relative_humidity_2m' => [65],
                    'precipitation_probability' => [30],
                    'precipitation' => [2.5],
                    'weather_code' => [2],
                    'cloud_cover' => [45],
                    'wind_speed_10m' => [14.2],
                    'wind_direction_10m' => [120],
                    'wind_gusts_10m' => [21.5],
                ]]);
            }

            return Http::response(['daily' => [
                'time' => ['2026-07-15'],
                'temperature_2m_max' => [26.2],
                'temperature_2m_min' => [18.1],
                'precipitation_probability_max' => [null],
                'precipitation_sum' => [4.2],
                'weather_code' => [3],
                'wind_speed_10m_max' => [17.8],
            ]]);
        });

        $service = app(OpenMeteoWeatherService::class);
        $context = [
            'location_label' => 'Home',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
        ];
        $base = [
            'query' => 'Return the interpreted weather.',
            'location' => 'Home',
            'units' => 'metric',
        ];

        $current = $service->weatherForStructuredIntent([
            ...$base,
            'kind' => 'weather',
        ], 'America/New_York', $context);
        $hourly = $service->weatherForStructuredIntent([
            ...$base,
            'kind' => 'forecast',
            'date' => '2026-07-15',
            'time' => '17:00',
        ], 'America/New_York', $context);
        $daily = $service->weatherForStructuredIntent([
            ...$base,
            'kind' => 'forecast',
            'date' => '2026-07-15',
        ], 'America/New_York', $context);

        $this->assertTrue($current['ok']);
        $this->assertSame('metric', data_get($current, 'weather.units'));
        $this->assertSame(21.0, data_get($current, 'weather.temperature_c'));
        $this->assertSame(1.2, data_get($current, 'weather.precipitation_mm'));
        $this->assertSame(12.0, data_get($current, 'weather.wind_speed_kmh'));
        $this->assertStringContainsString('21°C', $current['text']);
        $this->assertStringContainsString('12 km/h', $current['text']);
        $this->assertStringContainsString('1.2 mm', $current['text']);

        $this->assertTrue($hourly['ok']);
        $this->assertSame('metric', data_get($hourly, 'weather.units'));
        $this->assertSame(23.0, data_get($hourly, 'weather.temperature_c'));
        $this->assertSame(2.5, data_get($hourly, 'weather.precipitation_mm'));
        $this->assertSame(14.0, data_get($hourly, 'weather.wind_speed_kmh'));
        $this->assertStringContainsString('At 17:00 on 2026-07-15', $hourly['text']);
        $this->assertStringContainsString('23°C', $hourly['text']);
        $this->assertStringContainsString('14 km/h', $hourly['text']);
        $this->assertStringNotContainsString('today', mb_strtolower($hourly['text']));
        $this->assertStringNotContainsString('tomorrow', mb_strtolower($hourly['text']));

        $this->assertTrue($daily['ok']);
        $this->assertSame('metric', data_get($daily, 'weather.units'));
        $this->assertSame(26.0, data_get($daily, 'weather.temperature_max_c'));
        $this->assertSame(4.2, data_get($daily, 'weather.precipitation_sum_mm'));
        $this->assertSame(18.0, data_get($daily, 'weather.wind_speed_max_kmh'));
        $this->assertStringStartsWith('2026-07-15 in Home', $daily['text']);
        $this->assertStringContainsString('26°C', $daily['text']);
        $this->assertStringContainsString('4.2 mm', $daily['text']);
        $this->assertStringContainsString('18 km/h', $daily['text']);
        $this->assertStringNotContainsString('today', mb_strtolower($daily['text']));
        $this->assertStringNotContainsString('tomorrow', mb_strtolower($daily['text']));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && data_get($request->data(), 'temperature_unit') === 'celsius'
            && data_get($request->data(), 'wind_speed_unit') === 'kmh'
            && data_get($request->data(), 'precipitation_unit') === 'mm');
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && (data_get($request->data(), 'temperature_unit') !== 'celsius'
                || data_get($request->data(), 'wind_speed_unit') !== 'kmh'
                || data_get($request->data(), 'precipitation_unit') !== 'mm'));
    }

    public function test_structured_weather_rejects_noncanonical_units_before_provider_execution(): void
    {
        Http::fake();

        $result = app(OpenMeteoWeatherService::class)->weatherForStructuredIntent([
            'query' => 'Return the interpreted weather.',
            'kind' => 'weather',
            'location' => 'Orlando, Florida',
            'units' => 'celsius',
        ], 'America/New_York');

        $this->assertFalse($result['ok']);
        $this->assertSame('weather_current', $result['kind']);
        $this->assertSame('weather_units_invalid', $result['error_code']);
        $this->assertSame('units', $result['field']);
        $this->assertSame(['imperial', 'metric'], $result['allowed_values']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertNothingSent();
    }

    public function test_structured_weather_rejects_missing_units_before_provider_execution(): void
    {
        Http::fake();

        $result = app(OpenMeteoWeatherService::class)->weatherForStructuredIntent([
            'query' => 'Return the interpreted weather.',
            'kind' => 'weather',
            'location' => 'Orlando, Florida',
        ], 'America/New_York');

        $this->assertFalse($result['ok']);
        $this->assertSame('weather_current', $result['kind']);
        $this->assertSame('weather_units_invalid', $result['error_code']);
        $this->assertSame('units', $result['field']);
        $this->assertSame(['imperial', 'metric'], $result['allowed_values']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertNothingSent();
    }

    public function test_structured_forecast_rejects_relative_invalid_and_noncanonical_date_fields(): void
    {
        Http::fake();
        $service = app(OpenMeteoWeatherService::class);
        $base = [
            'query' => 'Return the selected forecast.',
            'kind' => 'forecast',
            'location' => 'Orlando, Florida',
            'units' => 'imperial',
        ];

        foreach (['target_date', 'forecast_date'] as $field) {
            $result = $service->weatherForStructuredIntent([
                ...$base,
                $field => '2026-07-15',
            ], 'America/New_York');

            $this->assertFalse($result['ok']);
            $this->assertSame('typed_weather_arguments_invalid', $result['error_code']);
            $this->assertSame([$field], $result['unsupported_fields']);
            $this->assertMachineReadableFailureReceipt($result);
        }

        $relativeDate = $service->weatherForStructuredIntent([
            ...$base,
            'date' => 'tomorrow',
        ], 'America/New_York');
        $this->assertFalse($relativeDate['ok']);
        $this->assertSame('weather_forecast_date_invalid', $relativeDate['error_code']);
        $this->assertSame('YYYY-MM-DD', $relativeDate['required_format']);
        $this->assertMachineReadableFailureReceipt($relativeDate);

        $invalidCalendarDate = $service->weatherForStructuredIntent([
            ...$base,
            'date' => '2026-02-30',
        ], 'America/New_York');
        $this->assertFalse($invalidCalendarDate['ok']);
        $this->assertSame('weather_forecast_date_invalid', $invalidCalendarDate['error_code']);
        $this->assertSame('YYYY-MM-DD', $invalidCalendarDate['required_format']);
        $this->assertMachineReadableFailureReceipt($invalidCalendarDate);
        Http::assertNothingSent();
    }

    public function test_structured_current_weather_rejects_dates_and_forecast_rejects_spoken_times(): void
    {
        Http::fake();
        $service = app(OpenMeteoWeatherService::class);

        $currentWithDate = $service->weatherForStructuredIntent([
            'query' => 'Return current conditions.',
            'kind' => 'weather',
            'location' => 'Orlando, Florida',
            'date' => '2026-07-15',
            'units' => 'imperial',
        ], 'America/New_York');
        $this->assertFalse($currentWithDate['ok']);
        $this->assertSame('weather_current_temporal_not_allowed', $currentWithDate['error_code']);
        $this->assertSame(['date', 'time'], $currentWithDate['disallowed_fields']);
        $this->assertMachineReadableFailureReceipt($currentWithDate);

        $forecastWithSpokenTime = $service->weatherForStructuredIntent([
            'query' => 'Return the selected forecast.',
            'kind' => 'forecast',
            'location' => 'Orlando, Florida',
            'date' => '2026-07-15',
            'time' => '5 PM',
            'units' => 'imperial',
        ], 'America/New_York');
        $this->assertFalse($forecastWithSpokenTime['ok']);
        $this->assertSame('weather_hourly_datetime_invalid', $forecastWithSpokenTime['error_code']);
        $this->assertSame(['date' => 'YYYY-MM-DD', 'time' => 'HH:MM'], $forecastWithSpokenTime['required_formats']);
        $this->assertMachineReadableFailureReceipt($forecastWithSpokenTime);
        Http::assertNothingSent();
    }

    public function test_typed_places_uses_the_explicit_search_term_and_location_verbatim(): void
    {
        config()->set('services.hermes_runtime.google_places_enabled', true);
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');
        config()->set('services.hermes_runtime.osm_places_enabled', false);

        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed places lookup',
            'status' => 'active',
        ]);

        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->once();

        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');

        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => 'Orlando, FL, USA',
                        'geometry' => ['location' => ['lat' => 28.5383, 'lng' => -81.3792]],
                        'address_components' => [],
                    ]],
                ]);
            }

            if ($request->url() === 'https://places.googleapis.com/v1/places:searchText') {
                return Http::response(['places' => [[
                    'id' => 'ups-orlando',
                    'displayName' => ['text' => 'The UPS Store'],
                    'formattedAddress' => '123 Main St, Orlando, FL 32801',
                    'location' => ['latitude' => 28.539, 'longitude' => -81.38],
                    'googleMapsUri' => 'https://maps.example/ups-orlando',
                    'businessStatus' => 'OPERATIONAL',
                    'types' => ['shipping_service'],
                ]]]);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'The UPS Store',
            'kind' => 'places',
            'location' => 'Orlando, Florida',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('google_places', $result['provider']);
        $this->assertSame('The UPS Store', $result['query']);
        $this->assertSame('Orlando, Florida', $result['location']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')
            && $request['address'] === 'Orlando, Florida');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://places.googleapis.com/v1/places:searchText'
            && $request['textQuery'] === 'The UPS Store Orlando, Florida');
    }

    public function test_ambiguous_places_origin_returns_to_hermes_without_selecting_the_first_geocode(): void
    {
        config()->set('services.hermes_runtime.google_places_enabled', true);
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');
        config()->set('services.hermes_runtime.osm_places_enabled', true);

        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Ambiguous places origin',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->once();
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');

        Http::fake(fn ($request) => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')
            ? Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => 'Springfield, IL, USA',
                        'geometry' => ['location' => ['lat' => 39.8017, 'lng' => -89.6436]],
                        'address_components' => [],
                    ],
                    [
                        'formatted_address' => 'Springfield, MO, USA',
                        'geometry' => ['location' => ['lat' => 37.2089, 'lng' => -93.2923]],
                        'address_components' => [],
                    ],
                ],
            ])
            : Http::response(['unexpected' => true], 500));

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'The UPS Store',
            'kind' => 'places',
            'location' => 'Springfield',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('places_location_ambiguous', $result['error_code']);
        $this->assertFalse($result['fallback_allowed']);
        $this->assertCount(2, $result['candidates']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://places.googleapis.com/v1/places:searchText');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'photon'));
    }

    public function test_typed_places_failures_remain_scoped_and_never_fall_through_to_general_search(): void
    {
        config()->set('services.hermes_runtime.google_places_enabled', true);
        config()->set('services.hermes_runtime.google_maps_api_key', 'google-test-key');
        config()->set('services.hermes_runtime.osm_places_enabled', true);
        config()->set('services.hermes_runtime.tavily_search_enabled', true);
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');
        config()->set('services.hermes_runtime.api_key', 'openai-test-key');

        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Scoped places failure',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->twice();
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response(['status' => 'ZERO_RESULTS', 'results' => []]);
            }
            if (str_contains($request->url(), 'photon.komoot.io/api/')) {
                return Http::response(['features' => []]);
            }

            return Http::response(['unexpected' => true], 500);
        });

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'The UPS Store',
            'kind' => 'places',
            'location' => 'Nowhere, Florida',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('places', $result['provider']);
        $this->assertSame('places', $result['kind']);
        $this->assertSame('places_not_found', $result['error_code']);
        $this->assertFalse($result['fallback_allowed']);
        $this->assertSame('The UPS Store', $result['query']);
        $this->assertSame('Nowhere, Florida', $result['location']);
        $this->assertSame([
            ['provider' => 'google_places', 'error_code' => 'places_location_not_found'],
            ['provider' => 'osm_places', 'error_code' => 'osm_location_not_found'],
        ], $result['provider_failures']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/responses'));
    }

    public function test_typed_places_without_a_scoped_provider_returns_terminal_unavailable(): void
    {
        config()->set('services.hermes_runtime.google_places_enabled', false);
        config()->set('services.hermes_runtime.osm_places_enabled', false);
        config()->set('services.hermes_runtime.tavily_search_enabled', true);
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');
        config()->set('services.hermes_runtime.api_key', 'openai-test-key');

        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Unavailable scoped places providers',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldNotReceive('recordDirectCall');
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');
        Http::fake();

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'The UPS Store',
            'kind' => 'places',
            'location' => 'Orlando, Florida',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('places', $result['provider']);
        $this->assertSame('places_lookup_unavailable', $result['error_code']);
        $this->assertFalse($result['fallback_allowed']);
        $this->assertSame([], $result['provider_failures']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertNothingSent();
    }

    public function test_typed_places_rejects_unstructured_query_without_location_before_any_provider_call(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed places lookup',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldNotReceive('estimateTokens');
        $usage->shouldNotReceive('preflightDirect');
        $usage->shouldNotReceive('recordDirectCall');
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldNotReceive('externalLookupModel');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');
        Http::fake();

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'Find the nearest The UPS Store in Orlando, Florida',
            'kind' => 'places',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('typed_places_arguments_invalid', $result['error_code']);
        $this->assertSame(['location'], $result['required_fields']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertNothingSent();
    }

    public function test_typed_web_uses_the_explicit_topic_without_classifying_query_prose(): void
    {
        config()->set('services.hermes_runtime.tavily_search_enabled', true);
        config()->set('services.hermes_runtime.tavily_api_key', 'tavily-test-key');

        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed web lookup',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldReceive('estimateTokens')->once()->andReturn(12);
        $usage->shouldReceive('preflightDirect')->once()->andReturn(['allowed' => true]);
        $usage->shouldReceive('recordDirectCall')->once();
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldReceive('externalLookupModel')->once()->andReturn('typed-provider');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'request_id' => 'typed-topic-1',
                'answer' => 'A receipt-backed news result.',
                'results' => [],
                'usage' => ['credits' => 1],
            ]),
        ]);

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'Apple stock earnings announcement',
            'kind' => 'web',
            'topic' => 'news',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('tavily_search', $result['provider']);
        $this->assertSame('news', $result['topic']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search'
            && $request['query'] === 'Apple stock earnings announcement'
            && $request['topic'] === 'news');
    }

    public function test_typed_web_rejects_missing_topic_before_any_provider_call(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed web lookup',
            'status' => 'active',
        ]);
        $usage = Mockery::mock(AiUsageService::class);
        $usage->shouldNotReceive('estimateTokens');
        $usage->shouldNotReceive('preflightDirect');
        $usage->shouldNotReceive('recordDirectCall');
        $settings = Mockery::mock(AdminSettingsService::class);
        $settings->shouldNotReceive('externalLookupModel');
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');
        Http::fake();

        $result = (new LiveLookupService($usage, $settings, $weather))->lookupTyped($session, [
            'query' => 'What happened today?',
            'kind' => 'web',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('typed_lookup_topic_invalid', $result['error_code']);
        $this->assertSame('topic', $result['field']);
        $this->assertSame(['general', 'news', 'finance'], $result['allowed_values']);
        $this->assertMachineReadableFailureReceipt($result);
        Http::assertNothingSent();
    }

    public function test_typed_lookup_rejects_missing_provider_kind_before_any_provider_call(): void
    {
        $user = User::factory()->create();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => null,
            'created_by_user_id' => $user->id,
            'title' => 'Typed lookup',
            'status' => 'active',
        ]);
        $weather = Mockery::mock(OpenMeteoWeatherService::class);
        $weather->shouldNotReceive('weatherForIntent');
        $weather->shouldNotReceive('weatherForStructuredIntent');

        $result = (new LiveLookupService(
            Mockery::mock(AiUsageService::class),
            Mockery::mock(AdminSettingsService::class),
            $weather,
        ))->lookupTyped($session, ['query' => 'Tell me what is new']);

        $this->assertFalse($result['ok']);
        $this->assertSame('typed_lookup_kind_invalid', $result['error_code']);
        $this->assertSame('kind', $result['field']);
        $this->assertSame(['weather', 'forecast', 'places', 'web', 'general'], $result['allowed_values']);
        $this->assertMachineReadableFailureReceipt($result);
    }

    /** @param array<string,mixed> $receipt */
    private function assertMachineReadableFailureReceipt(array $receipt): void
    {
        $this->assertFalse((bool) ($receipt['ok'] ?? true));
        $this->assertIsString($receipt['error_code'] ?? null);

        $forbiddenKeys = [
            'message',
            'diagnostic_message',
            'question',
            'clarification',
            'user_facing_message',
        ];
        array_walk_recursive($receipt, function (mixed $_value, string|int $key) use ($forbiddenKeys): void {
            $this->assertNotContains((string) $key, $forbiddenKeys);
        });
    }
}
