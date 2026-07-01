<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaceController extends Controller
{
    public function autocomplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'input' => ['required', 'string', 'min:2', 'max:180'],
            'session_token' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (! $this->placesConfigured()) {
            return response()->json(['data' => ['enabled' => false, 'suggestions' => []]]);
        }

        $body = [
            'input' => trim($data['input']),
            'includedRegionCodes' => ['us'],
        ];
        if (! empty($data['session_token'])) {
            $body['sessionToken'] = $data['session_token'];
        }

        $suggestions = $this->autocompleteWithPlacesApiNew($body);
        if ($suggestions === []) {
            $suggestions = $this->autocompleteWithLegacyPlacesApi($data);
        }

        return response()->json(['data' => ['enabled' => true, 'suggestions' => $suggestions]]);
    }

    public function details(Request $request): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
            'session_token' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (! $this->placesConfigured()) {
            return response()->json(['message' => 'Google Places is not configured.'], 503);
        }

        $url = 'https://places.googleapis.com/v1/places/'.rawurlencode($data['place_id']);
        $place = $this->detailsWithPlacesApiNew($data['place_id']);
        if ($place === null) {
            $place = $this->detailsWithLegacyPlacesApi($data['place_id']);
        }

        if ($place === null) {
            return response()->json(['message' => 'Google Places could not return that place.'], 502);
        }

        return response()->json(['data' => $place]);
    }

    public function staticMap(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if (! $this->placesConfigured()) {
            return response()->json(['message' => 'Google Maps is not configured.'], 503);
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];

        try {
            $response = Http::connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->get('https://maps.googleapis.com/maps/api/staticmap', [
                    'center' => "{$lat},{$lng}",
                    'zoom' => 15,
                    'size' => '640x260',
                    'scale' => 2,
                    'maptype' => 'roadmap',
                    'markers' => "color:green|{$lat},{$lng}",
                    'key' => $this->googleMapsApiKey(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Google Static Map failed', [
                'lat' => $lat,
                'lng' => $lng,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Google Maps could not render that location.'], 502);
        }

        if (! $response->successful()) {
            Log::warning('Google Static Map returned an error', [
                'lat' => $lat,
                'lng' => $lng,
                'status' => $response->status(),
            ]);

            return response()->json(['message' => 'Google Maps could not render that location.'], 502);
        }

        return response($response->body(), 200)
            ->header('Content-Type', $response->header('Content-Type', 'image/png'))
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function placesConfigured(): bool
    {
        return (bool) config('services.hermes_runtime.google_places_enabled', true)
            && $this->googleMapsApiKey() !== '';
    }

    private function autocompleteWithPlacesApiNew(array $body): array
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsApiKey(),
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat',
                ])
                ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->post('https://places.googleapis.com/v1/places:autocomplete', $body);
        } catch (ConnectionException $exception) {
            Log::warning('Google Places autocomplete new endpoint failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Google Places autocomplete new endpoint returned an error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [];
        }

        return collect($response->json('suggestions', []))
            ->map(fn ($suggestion) => $suggestion['placePrediction'] ?? null)
            ->filter()
            ->map(function (array $prediction): array {
                $structured = $prediction['structuredFormat'] ?? [];

                return [
                    'place_id' => (string) ($prediction['placeId'] ?? ''),
                    'primary_text' => (string) ($structured['mainText']['text'] ?? $prediction['text']['text'] ?? ''),
                    'secondary_text' => (string) ($structured['secondaryText']['text'] ?? ''),
                    'full_text' => (string) ($prediction['text']['text'] ?? ''),
                ];
            })
            ->filter(fn (array $suggestion): bool => $suggestion['place_id'] !== '' && $suggestion['primary_text'] !== '')
            ->values()
            ->all();
    }

    private function autocompleteWithLegacyPlacesApi(array $data): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                    'input' => trim((string) $data['input']),
                    'components' => 'country:us',
                    'sessiontoken' => $data['session_token'] ?? null,
                    'key' => $this->googleMapsApiKey(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Google Places legacy autocomplete failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful() || $response->json('status') !== 'OK') {
            Log::warning('Google Places legacy autocomplete returned an error', [
                'status' => $response->status(),
                'google_status' => $response->json('status'),
                'error_message' => $response->json('error_message'),
            ]);

            return [];
        }

        return collect($response->json('predictions', []))
            ->map(function (array $prediction): array {
                $structured = $prediction['structured_formatting'] ?? [];

                return [
                    'place_id' => (string) ($prediction['place_id'] ?? ''),
                    'primary_text' => (string) ($structured['main_text'] ?? $prediction['description'] ?? ''),
                    'secondary_text' => (string) ($structured['secondary_text'] ?? ''),
                    'full_text' => (string) ($prediction['description'] ?? ''),
                ];
            })
            ->filter(fn (array $suggestion): bool => $suggestion['place_id'] !== '' && $suggestion['primary_text'] !== '')
            ->values()
            ->all();
    }

    private function detailsWithPlacesApiNew(string $placeId): ?array
    {
        $url = 'https://places.googleapis.com/v1/places/'.rawurlencode($placeId);
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->googleMapsApiKey(),
                    'X-Goog-FieldMask' => 'id,displayName,formattedAddress,location,googleMapsUri',
                ])
                ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::warning('Google Place details new endpoint failed', [
                'place_id' => $placeId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google Place details new endpoint returned an error', [
                'place_id' => $placeId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return null;
        }

        $place = $response->json();

        return [
            'place_id' => (string) ($place['id'] ?? $placeId),
            'name' => (string) ($place['displayName']['text'] ?? ''),
            'formatted_address' => (string) ($place['formattedAddress'] ?? ''),
            'latitude' => $place['location']['latitude'] ?? null,
            'longitude' => $place['location']['longitude'] ?? null,
            'google_maps_uri' => (string) ($place['googleMapsUri'] ?? ''),
        ];
    }

    private function detailsWithLegacyPlacesApi(string $placeId): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout((float) config('services.hermes_runtime.google_places_connect_timeout', 2))
                ->timeout((float) config('services.hermes_runtime.google_places_timeout', 6))
                ->get('https://maps.googleapis.com/maps/api/place/details/json', [
                    'place_id' => $placeId,
                    'fields' => 'place_id,name,formatted_address,geometry,url',
                    'key' => $this->googleMapsApiKey(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Google Place details legacy endpoint failed', [
                'place_id' => $placeId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || $response->json('status') !== 'OK') {
            Log::warning('Google Place details legacy endpoint returned an error', [
                'place_id' => $placeId,
                'status' => $response->status(),
                'google_status' => $response->json('status'),
                'error_message' => $response->json('error_message'),
            ]);

            return null;
        }

        $place = $response->json('result', []);

        return [
            'place_id' => (string) ($place['place_id'] ?? $placeId),
            'name' => (string) ($place['name'] ?? ''),
            'formatted_address' => (string) ($place['formatted_address'] ?? ''),
            'latitude' => $place['geometry']['location']['lat'] ?? null,
            'longitude' => $place['geometry']['location']['lng'] ?? null,
            'google_maps_uri' => (string) ($place['url'] ?? ''),
        ];
    }

    private function googleMapsApiKey(): string
    {
        return trim((string) config('services.hermes_runtime.google_maps_api_key', ''));
    }
}
