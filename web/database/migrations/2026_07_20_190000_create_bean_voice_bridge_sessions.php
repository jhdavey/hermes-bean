<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bean_voice_bridge_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bean_session_id')->nullable()->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('conversation_id')->nullable()->unique();
            $table->string('client_timezone')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_transcript_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bean_voice_bridge_sessions');
    }
};
