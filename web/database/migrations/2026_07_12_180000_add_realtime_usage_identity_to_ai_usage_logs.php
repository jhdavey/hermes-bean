<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->uuid('usage_session_id')->nullable()->unique()->after('conversation_message_id');
            $table->string('provider_event_id', 191)->nullable()->unique()->after('usage_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropUnique(['provider_event_id']);
            $table->dropUnique(['usage_session_id']);
            $table->dropColumn(['provider_event_id', 'usage_session_id']);
        });
    }
};
