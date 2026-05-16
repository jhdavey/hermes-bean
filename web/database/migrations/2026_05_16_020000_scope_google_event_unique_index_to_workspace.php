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
                $table->dropUnique('cal_events_user_cal_event_unique');
            });
        } catch (Throwable) {
            // The previous cross-workspace unique index may already be absent on partially migrated databases.
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->unique(
                    ['workspace_id', 'user_id', 'google_calendar_id', 'google_event_id'],
                    'cal_events_workspace_user_cal_event_unique'
                );
            });
        } catch (Throwable) {
            // The workspace-scoped index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropUnique('cal_events_workspace_user_cal_event_unique');
            });
        } catch (Throwable) {
            // The workspace-scoped index may not exist.
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->unique(['user_id', 'google_calendar_id', 'google_event_id'], 'cal_events_user_cal_event_unique');
            });
        } catch (Throwable) {
            // The legacy index may already exist or existing workspace duplicates may prevent recreating it.
        }
    }
};
