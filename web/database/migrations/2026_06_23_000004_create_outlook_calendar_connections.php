<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlook_calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('outlook_account_email')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->string('status')->default('pending')->index();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('oauth_state')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasColumn('calendar_events', 'outlook_event_id')) {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->string('outlook_event_id')->nullable()->after('google_updated_at');
                $table->string('outlook_calendar_id')->nullable()->after('outlook_event_id')->index();
                $table->timestamp('outlook_updated_at')->nullable()->after('outlook_calendar_id');
            });
        }

        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->unique(['user_id', 'outlook_calendar_id', 'outlook_event_id'], 'cal_events_user_outlook_event_unique');
            });
        } catch (Throwable) {
            // The index may already exist on a partially migrated database.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropUnique('cal_events_user_outlook_event_unique');
            });
        } catch (Throwable) {
            // The index may not exist on a partially migrated database.
        }

        if (Schema::hasColumn('calendar_events', 'outlook_event_id')) {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->dropColumn(['outlook_event_id', 'outlook_calendar_id', 'outlook_updated_at']);
            });
        }

        Schema::dropIfExists('outlook_calendar_connections');
    }
};
