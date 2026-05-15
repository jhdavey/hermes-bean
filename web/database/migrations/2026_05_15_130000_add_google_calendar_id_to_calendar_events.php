<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'google_event_id']);
            $table->string('google_calendar_id')->nullable()->after('google_event_id')->index();
            $table->unique(['user_id', 'google_calendar_id', 'google_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'google_calendar_id', 'google_event_id']);
            $table->dropColumn('google_calendar_id');
            $table->unique(['user_id', 'google_event_id']);
        });
    }
};
