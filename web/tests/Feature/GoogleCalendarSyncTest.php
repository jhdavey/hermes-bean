<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
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
        $this->assertStringContainsString('calendar.readonly', $query['scope']);

        $this->get('/api/google-calendar/callback?state='.$query['state'].'&code=oauth-code')->assertOk();

        $this->withToken($token)->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.status', 'connected');

        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Google design review',
            'google_event_id' => 'google-event-1',
            'category' => 'Google Calendar',
            'color' => '#4285F4',
        ]);

        $connection = GoogleCalendarConnection::firstOrFail();
        $this->assertSame('refresh-token', Crypt::decryptString($connection->refresh_token_encrypted));
        $this->assertSame('sync-token-1', $connection->sync_token);
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
