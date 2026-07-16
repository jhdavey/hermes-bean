<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_map_supports_dark_mode_styles(): void
    {
        config()->set('services.places.google_places_enabled', true);
        config()->set('services.places.google_maps_api_key', 'google-test-key');

        Http::fake([
            'maps.googleapis.com/maps/api/staticmap*' => Http::response('png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->withToken($this->bearerToken())
            ->get('/api/places/static-map?lat=28.5&lng=-81.3&theme=dark')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return str_starts_with($url, 'https://maps.googleapis.com/maps/api/staticmap?')
                && str_contains($url, 'center=28.5,-81.3')
                && str_contains($url, 'markers=color:0x78d58c|28.5,-81.3')
                && str_contains($url, 'style=element:geometry|color:0x111820')
                && str_contains($url, 'style=feature:water|element:geometry|color:0x0a2230')
                && ! str_contains($url, 'style%5B0%5D');
        });
    }

    private function bearerToken(): string
    {
        $user = User::factory()->create([
            'email' => 'dark-map@example.com',
        ]);
        $token = bin2hex(random_bytes(32));

        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
