<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some pre-MVP imports wrote JSON metadata as a JSON string (for example
     * "{\"google_calendar_ids\":[...]}"), which Laravel correctly returns as
     * a string. Normalize those legacy rows back into JSON objects/arrays so API
     * clients receive one stable shape.
     */
    public function up(): void
    {
        foreach ($this->metadataTables() as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'metadata')) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('metadata')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        $normalized = $this->normalizeJsonString($row->metadata);
                        if ($normalized === null) {
                            continue;
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['metadata' => json_encode($normalized, JSON_THROW_ON_ERROR)]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Data normalization is intentionally one-way.
    }

    /**
     * @return list<string>
     */
    private function metadataTables(): array
    {
        return [
            'tasks',
            'reminders',
            'calendar_events',
            'event_categories',
            'workspaces',
            'workspace_memberships',
            'workspace_links',
            'google_calendar_connections',
        ];
    }

    private function normalizeJsonString(mixed $raw): mixed
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_string($decoded)) {
            return null;
        }

        try {
            $nested = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($nested) ? $nested : null;
    }
};
