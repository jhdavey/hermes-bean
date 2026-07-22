<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WorkspaceGoogleCalendarMapping;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google_calendar.client_id', 'google-client-id');
        config()->set('services.google_calendar.client_secret', 'google-client-secret');
        config()->set('services.google_calendar.redirect_uri', 'https://heybean.test/api/google-calendar/callback');
        Carbon::setTestNow('2026-05-15T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_can_start_google_calendar_oauth_and_callback_imports_events(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600,
            ]),
            'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                ],
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'google-event-1',
                    'summary' => 'Google design review',
                    'description' => 'Imported from Google',
                    'location' => 'Meet',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-20T16:00:00Z'],
                    'updated' => '2026-05-15T12:00:00Z',
                    'htmlLink' => 'https://calendar.google.com/event?eid=abc',
                ]],
                'nextSyncToken' => 'sync-token-1',
            ]),
        ]);

        $token = $this->apiToken('calendar-sync@example.com');

        $authUrl = $this->withToken($token)->postJson('/api/google-calendar/auth-url')
            ->assertOk()
            ->assertJsonPath('data.auth_url', fn (string $url): bool => str_contains($url, 'accounts.google.com'))
            ->json('data.auth_url');

        parse_str(parse_url($authUrl, PHP_URL_QUERY), $query);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://heybean.test/api/google-calendar/callback', $query['redirect_uri']);
        $this->assertStringContainsString('auth/calendar', $query['scope']);

        $this->get('/api/google-calendar/callback?state='.$query['state'].'&code=oauth-code')->assertOk();

        $this->withToken($token)->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Google design review',
            'google_event_id' => 'google-event-1',
            'category' => 'Primary',
            'color' => '#4285F4',
            'status' => 'scheduled',
        ]);
        $event = CalendarEvent::where('google_event_id', 'google-event-1')->sole();
        $this->assertSame('confirmed', $event->metadata['google_event_status']);

        $connection = GoogleCalendarConnection::firstOrFail();
        $this->assertSame('refresh-token', Crypt::decryptString($connection->refresh_token_encrypted));
        $this->assertSame('sync-token-1', $connection->sync_token);
    }

    public function test_google_timed_events_import_preserves_offset_instant(): void
    {
        $token = $this->apiToken('calendar-instant@example.com');
        $user = User::where('email', 'calendar-instant@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'sync_tokens' => ['primary' => 'stale-wall-clock-token'],
                'google_datetime_import_mode' => 'wall_clock_v1',
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'offset-event-1',
                    'summary' => 'Afternoon Google block',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T15:15:00-04:00', 'timeZone' => 'America/New_York'],
                    'end' => ['dateTime' => '2026-05-20T17:45:00-04:00', 'timeZone' => 'America/New_York'],
                ]],
                'nextSyncToken' => 'instant-token',
            ]),
        ]);

        $response = $this->withToken($token)->getJson('/api/calendar-events')->assertOk();

        $event = CalendarEvent::where('google_event_id', 'offset-event-1')->firstOrFail();
        $this->assertSame('2026-05-20 19:15:00', $event->getRawOriginal('starts_at'));
        $this->assertSame('2026-05-20 21:45:00', $event->getRawOriginal('ends_at'));
        $this->assertSame('2026-05-20T19:15:00+00:00', $event->starts_at->toIso8601String());
        $this->assertSame('2026-05-20T21:45:00+00:00', $event->ends_at->toIso8601String());
        $response->assertJsonPath('data.0.starts_at', '2026-05-20T19:15:00.000000Z')
            ->assertJsonPath('data.0.ends_at', '2026-05-20T21:45:00.000000Z');
        $connection = GoogleCalendarConnection::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('instant_v1', $connection->metadata['google_datetime_import_mode']);
        $this->assertContains('instant-token', $connection->metadata['sync_tokens']);
        $this->assertNotContains('stale-wall-clock-token', $connection->metadata['sync_tokens']);
    }

    public function test_user_can_select_google_calendars_and_sync_imports_only_selected_calendars(): void
    {
        $token = $this->premiumApiToken('calendar-select@example.com');
        $user = User::where('email', 'calendar-select@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                    ['id' => 'holiday@group.v.calendar.google.com', 'summary' => 'US Holidays', 'accessRole' => 'reader', 'backgroundColor' => '#0B8043'],
                    ['id' => 'sports@example.com', 'summary' => 'Sports', 'accessRole' => 'reader', 'backgroundColor' => '#F4511E'],
                ],
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'primary-event-1',
                    'summary' => 'Primary only event',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-20T16:00:00Z'],
                ]],
                'nextSyncToken' => 'primary-token',
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/holiday%40group.v.calendar.google.com/events*' => Http::response([
                'items' => [[
                    'id' => 'holiday-event-1',
                    'summary' => 'Memorial Day',
                    'status' => 'confirmed',
                    'start' => ['date' => '2026-05-25'],
                    'end' => ['date' => '2026-05-26'],
                ]],
                'nextSyncToken' => 'holiday-token',
            ]),
        ]);

        $this->withToken($token)->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.calendars.0.id', 'primary')
            ->assertJsonPath('data.calendars.0.selected', true)
            ->assertJsonPath('data.calendars.1.id', 'holiday@group.v.calendar.google.com')
            ->assertJsonPath('data.calendars.1.selected', false);

        $this->withToken($token)->patchJson('/api/google-calendar/calendars', [
            'selected_calendar_ids' => ['primary', 'holiday@group.v.calendar.google.com'],
            'default_calendar_id' => 'primary',
        ])->assertOk()
            ->assertJsonPath('data.selected_calendar_ids', ['primary', 'holiday@group.v.calendar.google.com']);

        $this->withToken($token)->postJson('/api/google-calendar/sync')
            ->assertOk()
            ->assertJsonPath('data.imported', 2);

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Primary only event',
            'google_event_id' => 'primary-event-1',
            'google_calendar_id' => 'primary',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Memorial Day',
            'google_event_id' => 'holiday-event-1',
            'google_calendar_id' => 'holiday@group.v.calendar.google.com',
        ]);
        $holiday = CalendarEvent::where('google_event_id', 'holiday-event-1')->firstOrFail();
        $this->assertTrue($holiday->metadata['all_day']);
        $this->assertSame('2026-05-25', $holiday->metadata['all_day_start_date']);
        $this->assertSame('2026-05-26', $holiday->metadata['all_day_exclusive_end_date']);
    }

    public function test_google_sync_preserves_heybean_category_on_exported_events(): void
    {
        $token = $this->apiToken('calendar-preserve-category@example.com');
        $user = User::where('email', 'calendar-preserve-category@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'calendar-preserve-category@example.com', 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                ],
            ],
        ]);
        CalendarEvent::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id' => 'google-family-1',
            'title' => 'Family dinner',
            'category' => 'Family',
            'color' => '#AF52DE',
            'starts_at' => '2026-05-20T22:00:00Z',
            'ends_at' => '2026-05-20T23:00:00Z',
            'metadata' => [
                'source' => 'heybean',
                'google_calendar_ids' => ['primary'],
                'google_event_exports' => [
                    'primary' => ['event_id' => 'google-family-1'],
                ],
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'google-family-1',
                    'summary' => 'Family dinner updated in Google',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T22:30:00Z'],
                    'end' => ['dateTime' => '2026-05-20T23:30:00Z'],
                    'updated' => '2026-05-15T12:00:00Z',
                    'htmlLink' => 'https://calendar.google.com/event?eid=family',
                ]],
                'nextSyncToken' => 'preserve-token',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/google-calendar/sync')->assertOk();

        $event = CalendarEvent::where('google_event_id', 'google-family-1')->firstOrFail();
        $this->assertSame('Family dinner updated in Google', $event->title);
        $this->assertSame('Family', $event->category);
        $this->assertSame('#AF52DE', $event->color);
        $this->assertSame('heybean', $event->metadata['source']);
        $this->assertSame('calendar-preserve-category@example.com', $event->metadata['google_calendar_summary']);
    }

    public function test_google_sync_preserves_existing_non_email_categories_even_after_old_metadata_overwrite(): void
    {
        $token = $this->apiToken('calendar-preserve-existing-category@example.com');
        $user = User::where('email', 'calendar-preserve-existing-category@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'calendar-preserve-existing-category@example.com', 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                ],
            ],
        ]);
        CalendarEvent::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id' => 'google-family-legacy-1',
            'title' => 'Family dinner',
            'category' => 'Family',
            'color' => '#AF52DE',
            'starts_at' => '2026-05-20T22:00:00Z',
            'ends_at' => '2026-05-20T23:00:00Z',
            'metadata' => [
                'source' => 'google_calendar',
                'google_calendar_id' => 'primary',
                'google_calendar_summary' => 'calendar-preserve-existing-category@example.com',
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'google-family-legacy-1',
                    'summary' => 'Family dinner updated in Google',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T22:30:00Z'],
                    'end' => ['dateTime' => '2026-05-20T23:30:00Z'],
                ]],
                'nextSyncToken' => 'preserve-existing-token',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/google-calendar/sync')->assertOk();

        $event = CalendarEvent::where('google_event_id', 'google-family-legacy-1')->firstOrFail();
        $this->assertSame('Family', $event->category);
        $this->assertSame('#AF52DE', $event->color);
        $this->assertSame('google_calendar', $event->metadata['source']);
    }

    public function test_google_sync_does_not_use_email_addresses_as_imported_event_categories(): void
    {
        $token = $this->apiToken('calendar-email-category@example.com');
        $user = User::where('email', 'calendar-email-category@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'calendar-email-category@example.com', 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                ],
            ],
        ]);
        CalendarEvent::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'google_calendar_id' => 'primary',
            'google_event_id' => 'google-email-category-existing-1',
            'title' => 'Existing imported block',
            'category' => 'calendar-email-category@example.com',
            'color' => '#AF52DE',
            'starts_at' => '2026-05-20T12:00:00Z',
            'ends_at' => '2026-05-20T13:00:00Z',
            'metadata' => [
                'source' => 'google_calendar',
                'google_calendar_id' => 'primary',
                'google_calendar_summary' => 'calendar-email-category@example.com',
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'google-email-category-1',
                    'summary' => 'Imported block',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-20T16:00:00Z'],
                ], [
                    'id' => 'google-email-category-existing-1',
                    'summary' => 'Existing imported block',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T12:00:00Z'],
                    'end' => ['dateTime' => '2026-05-20T13:00:00Z'],
                ]],
                'nextSyncToken' => 'email-category-token',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/google-calendar/sync')->assertOk();

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Imported block',
            'google_event_id' => 'google-email-category-1',
            'category' => 'Connected calendar',
            'color' => '#4285F4',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Existing imported block',
            'google_event_id' => 'google-email-category-existing-1',
            'category' => 'Connected calendar',
            'color' => '#4285F4',
        ]);
        $this->assertDatabaseMissing('calendar_events', [
            'google_event_id' => 'google-email-category-1',
            'category' => 'calendar-email-category@example.com',
        ]);
        $this->assertDatabaseMissing('calendar_events', [
            'google_event_id' => 'google-email-category-existing-1',
            'category' => 'calendar-email-category@example.com',
        ]);
    }

    public function test_workspace_calendar_event_listing_syncs_workspace_selected_google_calendars(): void
    {
        $token = $this->apiToken('workspace-calendar-sync@example.com');
        $user = User::where('email', 'workspace-calendar-sync@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'accessRole' => 'owner', 'backgroundColor' => '#4285F4'],
                    ['id' => 'wife@example.com', 'summary' => 'Lauren', 'accessRole' => 'reader', 'backgroundColor' => '#D50000'],
                ],
            ],
        ]);
        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'wife@example.com',
            'google_calendar_summary' => 'Lauren',
            'color' => '#D50000',
            'is_default_export' => false,
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/wife%40example.com/events*' => Http::response([
                'items' => [[
                    'id' => 'wife-event-1',
                    'summary' => 'Lauren dentist',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-20T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-20T16:00:00Z'],
                ]],
                'nextSyncToken' => 'wife-token',
            ]),
        ]);

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Lauren dentist']);

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Lauren dentist',
            'google_event_id' => 'wife-event-1',
            'google_calendar_id' => 'wife@example.com',
        ]);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/calendars/primary/events'));
    }

    public function test_unchecking_workspace_google_calendar_hides_existing_imported_events_and_stops_syncing_it(): void
    {
        $token = $this->apiToken('workspace-calendar-hidden@example.com');
        $user = User::where('email', 'workspace-calendar-hidden@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary', 'family@example.com'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'accessRole' => 'owner'],
                    ['id' => 'family@example.com', 'summary' => 'Family', 'accessRole' => 'reader'],
                ],
            ],
        ]);
        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'primary',
        ]);
        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'family@example.com',
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Visible main event',
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'google_event_id' => 'main-event-1',
            'google_calendar_id' => 'primary',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'primary'],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Hidden family event',
            'starts_at' => '2026-05-20T17:00:00Z',
            'ends_at' => '2026-05-20T18:00:00Z',
            'google_event_id' => 'family-event-1',
            'google_calendar_id' => 'family@example.com',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'family@example.com'],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Local workspace event',
            'starts_at' => '2026-05-20T19:00:00Z',
            'ends_at' => '2026-05-20T20:00:00Z',
        ]);

        $this->withToken($token)->patchJson('/api/workspaces/'.$workspaceId.'/google-calendars', [
            'google_calendar_ids' => ['primary'],
        ])->assertOk();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [],
                'nextSyncToken' => 'primary-token',
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/family%40example.com/events*' => Http::response([
                'items' => [[
                    'id' => 'family-event-2',
                    'summary' => 'Should not sync',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-21T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-21T16:00:00Z'],
                ]],
            ]),
        ]);

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Visible main event'])
            ->assertJsonFragment(['title' => 'Local workspace event'])
            ->assertJsonMissing(['title' => 'Hidden family event'])
            ->assertJsonMissing(['title' => 'Should not sync']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/calendars/family%40example.com/events'));
    }

    public function test_workspace_with_explicitly_empty_google_calendar_selection_hides_google_events_without_falling_back_to_personal_selection(): void
    {
        $token = $this->apiToken('workspace-calendar-empty@example.com');
        $user = User::where('email', 'workspace-calendar-empty@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [['id' => 'primary', 'summary' => 'Main calendar', 'accessRole' => 'owner']],
            ],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Hidden main event',
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'google_event_id' => 'main-hidden-1',
            'google_calendar_id' => 'primary',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'primary'],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Still visible local event',
            'starts_at' => '2026-05-20T19:00:00Z',
            'ends_at' => '2026-05-20T20:00:00Z',
        ]);

        $this->withToken($token)->patchJson('/api/workspaces/'.$workspaceId.'/google-calendars', [
            'google_calendar_ids' => [],
        ])->assertOk();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'main-hidden-2',
                    'summary' => 'Should not fall back sync',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => '2026-05-21T15:00:00Z'],
                    'end' => ['dateTime' => '2026-05-21T16:00:00Z'],
                ]],
            ]),
        ]);

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Still visible local event'])
            ->assertJsonMissing(['title' => 'Hidden main event'])
            ->assertJsonMissing(['title' => 'Should not fall back sync']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/calendars/primary/events'));
    }

    public function test_new_workspace_google_calendar_import_does_not_reuse_personal_sync_token(): void
    {
        $token = $this->apiToken('workspace-token-scope@example.com');
        $user = User::where('email', 'workspace-token-scope@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Davey Household');
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'sync_tokens' => [$personalWorkspaceId.':primary' => 'personal-token'],
                'google_datetime_import_mode' => 'instant_v1',
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'primary' => true, 'accessRole' => 'owner'],
                ],
            ],
        ]);
        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $household->id,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'primary',
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $personalWorkspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Personal copy of shared Google event',
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'google_calendar_id' => 'primary',
            'google_event_id' => 'household-existing-event-1',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'primary'],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/calendars/primary/events') && str_contains($request->url(), 'syncToken=')) {
                return Http::response(['items' => [], 'nextSyncToken' => 'household-incremental-token']);
            }

            if (str_contains($request->url(), '/calendars/primary/events')) {
                return Http::response([
                    'items' => [[
                        'id' => 'household-existing-event-1',
                        'summary' => 'Household mapped existing event',
                        'status' => 'confirmed',
                        'start' => ['dateTime' => '2026-05-20T15:00:00Z'],
                        'end' => ['dateTime' => '2026-05-20T16:00:00Z'],
                    ]],
                    'nextSyncToken' => 'household-full-token',
                ]);
            }

            return Http::response(['items' => []]);
        });

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$household->id)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Household mapped existing event']);

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'workspace_id' => $household->id,
            'title' => 'Household mapped existing event',
            'google_event_id' => 'household-existing-event-1',
            'google_calendar_id' => 'primary',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'workspace_id' => $personalWorkspaceId,
            'google_event_id' => 'household-existing-event-1',
            'title' => 'Personal copy of shared Google event',
        ]);
        $this->assertSame('personal-token', $connection->refresh()->metadata['sync_tokens'][$personalWorkspaceId.':primary']);
        $this->assertSame(2, CalendarEvent::where('google_event_id', 'household-existing-event-1')->count());
    }

    public function test_selected_primary_alias_calendars_do_not_duplicate_all_day_events(): void
    {
        $token = $this->apiToken('calendar-primary-alias@example.com');
        $user = User::where('email', 'calendar-primary-alias@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'google_account_email' => 'calendar-primary-alias@example.com',
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary', 'calendar-primary-alias@example.com'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'calendar-primary-alias@example.com', 'summary' => 'calendar-primary-alias@example.com', 'accessRole' => 'owner'],
                ],
            ],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Existing alias holiday',
            'starts_at' => '2026-05-15',
            'ends_at' => '2026-05-16',
            'google_event_id' => 'shared-all-day-1',
            'google_calendar_id' => 'calendar-primary-alias@example.com',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'calendar-primary-alias@example.com', 'all_day' => true],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Legacy alias holiday',
            'starts_at' => '2026-05-15',
            'ends_at' => '2026-05-16',
            'google_event_id' => 'shared-all-day-1',
            'metadata' => ['source' => 'google_calendar', 'google_calendar_id' => 'primary'],
        ]);

        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'primary',
        ]);
        WorkspaceGoogleCalendarMapping::create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connection->id,
            'google_calendar_id' => 'calendar-primary-alias@example.com',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'shared-all-day-1',
                    'summary' => 'Shared all-day holiday',
                    'status' => 'confirmed',
                    'start' => ['date' => '2026-05-15'],
                    'end' => ['date' => '2026-05-16'],
                    'htmlLink' => 'https://calendar.google.com/event?eid=shared',
                ]],
                'nextSyncToken' => 'primary-token',
            ]),
        ]);

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Shared all-day holiday']);

        $events = CalendarEvent::where('workspace_id', $workspaceId)
            ->where('google_event_id', 'shared-all-day-1')
            ->get();
        $this->assertCount(1, $events);
        $this->assertSame('primary', $events->first()->google_calendar_id);
        $this->assertTrue($events->first()->metadata['all_day']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/calendars/calendar-primary-alias%40example.com/events'));
    }

    public function test_local_calendar_create_and_update_do_not_write_to_google_calendar(): void
    {
        $token = $this->apiToken('calendar-local-only@example.com');
        $user = User::where('email', 'calendar-local-only@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'work@example.com',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary', 'work@example.com'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'accessRole' => 'owner'],
                    ['id' => 'work@example.com', 'summary' => 'Work', 'accessRole' => 'writer'],
                ],
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'should-not-be-written']),
        ]);

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Local client meeting',
            'all_day' => false,
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'metadata' => ['google_calendar_id' => 'work@example.com'],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson('/api/calendar-events/'.$eventId, [
            'title' => 'Updated client meeting',
            'starts_at' => '2026-05-20T17:00:00Z',
            'ends_at' => '2026-05-20T18:00:00Z',
            'metadata' => ['google_calendar_id' => 'work@example.com'],
        ])->assertOk();

        $event = CalendarEvent::findOrFail($eventId);
        $this->assertSame('Updated client meeting', $event->title);
        $this->assertNull($event->google_event_id);
        Http::assertNothingSent();
    }

    public function test_local_calendar_create_without_external_calendar_selection_does_not_write_to_google(): void
    {
        $token = $this->apiToken('calendar-no-external-export@example.com');
        $user = User::where('email', 'calendar-no-external-export@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'accessRole' => 'owner'],
                ],
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([
                'id' => 'should-not-be-created',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Local only planning',
            'all_day' => false,
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'metadata' => ['google_calendar_ids' => []],
        ])->assertCreated()
            ->assertJsonPath('data.google_event_id', null)
            ->assertJsonPath('data.google_calendar_id', null)
            ->assertJsonPath('data.metadata.google_calendar_ids', []);

        Http::assertNothingSent();
    }

    public function test_deleting_local_event_does_not_remove_google_calendar_copies(): void
    {
        $token = $this->apiToken('calendar-delete-export@example.com');
        $user = User::where('email', 'calendar-delete-export@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary', 'work@example.com'],
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Main calendar', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'work@example.com', 'summary' => 'Work', 'accessRole' => 'writer'],
                ],
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([], 204),
        ]);

        $event = CalendarEvent::create([
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'title' => 'Delete me externally',
            'starts_at' => '2026-05-20T15:00:00Z',
            'google_event_id' => 'google-primary-delete',
            'google_calendar_id' => 'primary',
            'metadata' => [
                'source' => 'heybean',
                'google_event_exports' => [
                    'primary' => ['event_id' => 'google-primary-delete'],
                    'work@example.com' => ['event_id' => 'google-work-delete'],
                ],
            ],
        ]);

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$event->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
        Http::assertNothingSent();
    }

    public function test_google_api_failure_messages_do_not_persist_sensitive_response_bodies(): void
    {
        $token = $this->apiToken('calendar-error-sanitize@example.com');
        $user = User::where('email', 'calendar-error-sanitize@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => ['selected_calendar_ids' => ['primary']],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'error' => [
                    'message' => 'sensitive upstream details access-token user@example.com',
                ],
            ], 500),
            'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [],
            ]),
        ]);

        $this->withToken($token)->postJson('/api/google-calendar/sync')
            ->assertStatus(422)
            ->assertJsonMissing(['message' => 'sensitive upstream details access-token user@example.com']);

        $connection = GoogleCalendarConnection::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Calendar sync failed.', $connection->last_error);
        $this->withToken($token)->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.last_error', 'Calendar sync failed.')
            ->assertJsonMissing(['last_error' => 'sensitive upstream details access-token user@example.com']);
    }

    public function test_manual_sync_upserts_and_deletes_google_calendar_events(): void
    {
        $token = $this->apiToken('calendar-resync@example.com');
        $user = User::where('email', 'calendar-resync@example.com')->firstOrFail();
        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'title' => 'Old title',
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'google_event_id' => 'google-event-1',
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'title' => 'Cancelled event',
            'starts_at' => '2026-05-21T15:00:00Z',
            'google_event_id' => 'google-event-cancelled',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id' => 'google-event-1',
                        'summary' => 'Updated Google title',
                        'status' => 'confirmed',
                        'start' => ['dateTime' => '2026-05-20T17:00:00Z'],
                        'end' => ['dateTime' => '2026-05-20T18:00:00Z'],
                    ],
                    ['id' => 'google-event-cancelled', 'status' => 'cancelled'],
                ],
                'nextSyncToken' => 'sync-token-2',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/google-calendar/sync')
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseHas('calendar_events', [
            'google_event_id' => 'google-event-1',
            'title' => 'Updated Google title',
        ]);
        $this->assertDatabaseMissing('calendar_events', ['google_event_id' => 'google-event-cancelled']);
    }
}
