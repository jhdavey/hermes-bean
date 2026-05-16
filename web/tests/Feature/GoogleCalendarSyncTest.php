<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WorkspaceGoogleCalendarMapping;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);

        $connection = GoogleCalendarConnection::firstOrFail();
        $this->assertSame('refresh-token', Crypt::decryptString($connection->refresh_token_encrypted));
        $this->assertSame('sync-token-1', $connection->sync_token);
    }

    public function test_user_can_select_google_calendars_and_sync_imports_only_selected_calendars(): void
    {
        $token = $this->apiToken('calendar-select@example.com');
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
                'sync_tokens' => ['primary' => 'personal-token'],
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
        $this->assertSame('personal-token', $connection->refresh()->metadata['sync_tokens']['primary']);
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

    public function test_local_calendar_create_and_update_write_to_selected_google_calendar(): void
    {
        $token = $this->apiToken('calendar-write@example.com');
        $user = User::where('email', 'calendar-write@example.com')->firstOrFail();
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
            'https://www.googleapis.com/calendar/v3/calendars/work%40example.com/events' => Http::response([
                'id' => 'google-created-1',
                'updated' => '2026-05-15T12:00:00Z',
                'htmlLink' => 'https://calendar.google.com/event?eid=created',
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/work%40example.com/events/google-created-1' => Http::response([
                'id' => 'google-created-1',
                'updated' => '2026-05-15T12:30:00Z',
                'htmlLink' => 'https://calendar.google.com/event?eid=updated',
            ]),
        ]);

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Local client meeting',
            'starts_at' => '2026-05-20T15:00:00Z',
            'ends_at' => '2026-05-20T16:00:00Z',
            'metadata' => ['google_calendar_id' => 'work@example.com'],
        ])->assertCreated()
            ->assertJsonPath('data.google_event_id', 'google-created-1')
            ->assertJsonPath('data.google_calendar_id', 'work@example.com')
            ->json('data.id');

        $this->withToken($token)->patchJson('/api/calendar-events/'.$eventId, [
            'title' => 'Updated client meeting',
            'starts_at' => '2026-05-20T17:00:00Z',
            'ends_at' => '2026-05-20T18:00:00Z',
            'metadata' => ['google_calendar_id' => 'work@example.com'],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/calendars/work%40example.com/events')
            && $request['summary'] === 'Local client meeting');
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/calendars/work%40example.com/events/google-created-1')
            && $request['summary'] === 'Updated client meeting');
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
