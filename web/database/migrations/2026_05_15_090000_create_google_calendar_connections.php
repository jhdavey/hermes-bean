<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_account_email')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->string('status')->default('pending')->index();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('sync_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('oauth_state')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('google_event_id')->nullable()->index();
            $table->timestamp('google_updated_at')->nullable();
            $table->unique(['user_id', 'google_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'google_event_id']);
            $table->dropColumn(['google_event_id', 'google_updated_at']);
        });

        Schema::dropIfExists('google_calendar_connections');
    }
};
