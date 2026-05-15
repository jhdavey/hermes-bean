<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'google_event_id']);
            });
        } catch (Throwable) {
            // The old index may already be gone if a previous deploy failed mid-migration.
        }

        if (! Schema::hasColumn('calendar_events', 'google_calendar_id')) {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->string('google_calendar_id')->nullable()->after('google_event_id')->index();
            });
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->unique(['user_id', 'google_calendar_id', 'google_event_id'], 'cal_events_user_cal_event_unique');
            });
        } catch (Throwable) {
            // The replacement index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropUnique('cal_events_user_cal_event_unique');
            });
        } catch (Throwable) {
            // The replacement index may not exist on partially migrated databases.
        }

        if (Schema::hasColumn('calendar_events', 'google_calendar_id')) {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropColumn('google_calendar_id');
            });
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->unique(['user_id', 'google_event_id']);
            });
        } catch (Throwable) {
            // The original index already exists.
        }
    }
};
