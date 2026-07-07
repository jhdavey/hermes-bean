<?php

namespace Tests\Feature;

use App\Models\PageViewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageViewAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_views_are_recorded_without_blocking_rendering(): void
    {
        $this->get('/pricing?utm_source=influencer&utm_medium=social&utm_campaign=summer')
            ->assertOk()
            ->assertCookie('hb_visitor');

        $event = PageViewEvent::firstOrFail();

        $this->assertSame('/pricing', $event->path);
        $this->assertSame('pricing', $event->route_name);
        $this->assertSame('influencer', $event->utm_source);
        $this->assertSame('social', $event->utm_medium);
        $this->assertSame('summer', $event->utm_campaign);
        $this->assertSame(200, $event->status_code);
        $this->assertNotEmpty($event->visitor_key);
    }
}
