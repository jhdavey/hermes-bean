<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bean_voice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bean_session_id')->nullable()->constrained('bean_sessions')->nullOnDelete();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('mode')->nullable()->index();
            $table->string('source')->nullable()->index();
            $table->string('label')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->unsignedBigInteger('occurred_at_ms')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['bean_session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bean_voice_events');
    }
};
