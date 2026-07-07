<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppleCalendarImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_import_apple_calendar_from_public_webcal_link(): void
    {
        Http::fake([
            'https://p42-caldav.icloud.com/published/2/*' => Http::response($this->icsFixture('Design review'), 200, [
                'Content-Type' => 'text/calendar',
            ]),
        ]);

        $token = $this->apiToken('apple-import@example.com');
        $user = User::where('email', 'apple-import@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);

        $this->withToken($token)->postJson('/api/apple-calendar/import', [
            'url' => 'webcal://p42-caldav.icloud.com/published/2/example-calendar',
            'workspace_id' => $workspace->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.workspace_id', $workspace->id);

        $event = CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('title', 'Design review')
            ->firstOrFail();

        $this->assertSame('Imported from Apple', $event->description);
        $this->assertSame('Studio', $event->location);
        $this->assertSame('Apple Calendar', $event->category);
        $this->assertSame('apple_calendar', $event->metadata['source']);
        $this->assertSame('event-1@example.com', $event->metadata['apple_calendar_uid']);
        $this->assertFalse((bool) $event->metadata['all_day']);
        $this->assertSame('2026-07-08T13:30:00.000000Z', $event->starts_at->toJSON());

        $allDay = CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('title', 'Beach day')
            ->firstOrFail();

        $this->assertTrue((bool) $allDay->metadata['all_day']);
        $this->assertSame('2026-07-11T00:00:00.000000Z', $allDay->starts_at->toJSON());
    }

    public function test_user_can_list_external_calendar_provider_presets(): void
    {
        $token = $this->apiToken('calendar-provider-presets@example.com');

        $this->withToken($token)->getJson('/api/external-calendars/providers')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'apple')
            ->assertJsonPath('data.1.key', 'google')
            ->assertJsonPath('data.2.key', 'outlook')
            ->assertJsonFragment(['key' => 'proton'])
            ->assertJsonFragment(['key' => 'yahoo'])
            ->assertJsonFragment(['key' => 'fastmail'])
            ->assertJsonFragment(['key' => 'nextcloud'])
            ->assertJsonFragment(['key' => 'ics']);
    }

    public function test_user_can_import_external_calendar_with_provider_preset(): void
    {
        Http::fake([
            'https://calendar.proton.me/api/calendar/v1/url/*' => Http::response($this->icsFixture('Proton design review'), 200, [
                'Content-Type' => 'text/calendar',
            ]),
        ]);

        $token = $this->apiToken('proton-import@example.com');

        $this->withToken($token)->postJson('/api/external-calendars/import', [
            'provider_key' => 'proton',
            'url' => 'https://calendar.proton.me/api/calendar/v1/url/example-calendar',
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.provider_key', 'proton')
            ->assertJsonPath('data.provider_label', 'Proton Calendar');

        $event = CalendarEvent::query()
            ->where('title', 'Proton design review')
            ->firstOrFail();

        $this->assertSame('external_calendar', $event->metadata['source']);
        $this->assertSame('proton', $event->metadata['external_calendar_provider']);
        $this->assertSame('Proton Calendar', $event->category);
    }

    public function test_reimport_updates_matching_apple_uid_without_duplicate_events(): void
    {
        Http::fake([
            'https://p42-caldav.icloud.com/published/2/*' => Http::sequence()
                ->push($this->icsFixture('Design review'), 200)
                ->push($this->icsFixture('Design review moved'), 200),
        ]);

        $token = $this->apiToken('apple-reimport@example.com');

        $this->withToken($token)->postJson('/api/apple-calendar/import', [
            'url' => 'https://p42-caldav.icloud.com/published/2/example-calendar',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/apple-calendar/import', [
            'url' => 'https://p42-caldav.icloud.com/published/2/example-calendar',
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(2, CalendarEvent::count());
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Design review moved',
            'location' => 'Studio',
        ]);
    }

    public function test_private_apple_calendar_import_url_is_rejected(): void
    {
        $token = $this->apiToken('apple-private-url@example.com');

        $this->withToken($token)->postJson('/api/apple-calendar/import', [
            'url' => 'http://127.0.0.1/published/calendar.ics',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'apple_calendar_import_failed');
    }

    private function icsFixture(string $summary): string
    {
        return <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Apple Inc.//iCloud Calendar//EN
BEGIN:VEVENT
UID:event-1@example.com
DTSTAMP:20260707T120000Z
DTSTART;TZID=America/New_York:20260708T093000
DTEND;TZID=America/New_York:20260708T103000
SUMMARY:{$summary}
DESCRIPTION:Imported from Apple
LOCATION:Studio
STATUS:CONFIRMED
SEQUENCE:1
END:VEVENT
BEGIN:VEVENT
UID:all-day@example.com
DTSTART;VALUE=DATE:20260711
DTEND;VALUE=DATE:20260712
SUMMARY:Beach day
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
ICS;
    }
}
