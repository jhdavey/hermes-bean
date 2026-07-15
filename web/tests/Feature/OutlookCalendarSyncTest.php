<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\OutlookCalendarConnection;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutlookCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlook_import_maps_provider_state_to_canonical_scheduled_status(): void
    {
        $token = $this->apiToken('outlook-canonical-status@example.com');
        $user = User::where('email', 'outlook-canonical-status@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        OutlookCalendarConnection::create([
            'user_id' => $user->id,
            'calendar_id' => 'calendar-1',
            'status' => 'connected',
            'access_token_encrypted' => Crypt::encryptString('outlook-access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('outlook-refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['calendar-1'],
                'calendars' => [[
                    'id' => 'calendar-1',
                    'summary' => 'Work',
                    'color' => '#0078D4',
                ]],
            ],
        ]);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/calendars/calendar-1/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'outlook-event-1',
                    'subject' => 'Outlook design review',
                    'bodyPreview' => 'Imported from Outlook',
                    'location' => ['displayName' => 'Conference room'],
                    'isAllDay' => false,
                    'isCancelled' => false,
                    'showAs' => 'tentative',
                    'responseStatus' => [
                        'response' => 'tentativelyAccepted',
                        'time' => '2026-07-14T12:00:00Z',
                    ],
                    'start' => ['dateTime' => '2026-07-15T09:00:00', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-07-15T10:00:00', 'timeZone' => 'UTC'],
                    'lastModifiedDateTime' => '2026-07-14T12:00:00Z',
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/me/calendars*' => Http::response([
                'value' => [[
                    'id' => 'calendar-1',
                    'name' => 'Work',
                    'isDefaultCalendar' => true,
                    'canEdit' => true,
                ]],
            ]),
        ]);

        $this->withToken($token)->postJson('/api/outlook-calendar/sync', [
            'workspace_id' => $workspace->id,
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $event = CalendarEvent::where('outlook_event_id', 'outlook-event-1')->sole();
        $this->assertSame('scheduled', $event->status);
        $this->assertSame('tentative', $event->metadata['outlook_show_as']);
        $this->assertSame('tentativelyAccepted', $event->metadata['outlook_response_status']['response']);
    }
}
