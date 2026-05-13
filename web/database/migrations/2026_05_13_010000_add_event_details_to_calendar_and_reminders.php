<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('location');
            $table->string('color', 20)->nullable()->after('category');
            $table->string('recurrence', 50)->nullable()->after('color');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->foreignId('calendar_event_id')
                ->nullable()
                ->after('conversation_session_id')
                ->constrained('calendar_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('calendar_event_id');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn(['category', 'color', 'recurrence']);
        });
    }
};
