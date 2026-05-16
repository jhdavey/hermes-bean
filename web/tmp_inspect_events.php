<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

App\Models\CalendarEvent::query()
    ->whereNotNull('google_event_id')
    ->latest('id')
    ->take(10)
    ->get(['id', 'title', 'starts_at', 'ends_at', 'google_calendar_id', 'metadata'])
    ->each(function ($e): void {
        echo json_encode([
            'id' => $e->id,
            'title' => $e->title,
            'starts_at_raw' => $e->getRawOriginal('starts_at'),
            'starts_iso' => $e->starts_at?->toIso8601String(),
            'ends_raw' => $e->getRawOriginal('ends_at'),
            'ends_iso' => $e->ends_at?->toIso8601String(),
            'calendar' => $e->google_calendar_id,
            'metadata' => $e->metadata,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    });
