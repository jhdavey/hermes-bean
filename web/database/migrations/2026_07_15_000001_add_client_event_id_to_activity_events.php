<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->string('client_event_id', 191)->nullable()->after('conversation_session_id');
            $table->unique(
                ['user_id', 'client_event_id'],
                'activity_events_user_client_event_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table): void {
            $table->dropUnique('activity_events_user_client_event_unique');
            $table->dropColumn('client_event_id');
        });
    }
};
