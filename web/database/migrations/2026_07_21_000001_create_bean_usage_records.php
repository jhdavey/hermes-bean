<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bean_usage_records')) {
            return;
        }

        Schema::create('bean_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bean_session_id')->nullable()->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->foreignId('bean_voice_event_id')->nullable()->constrained('bean_voice_events')->nullOnDelete();
            $table->string('provider', 60)->index();
            $table->string('service', 80)->nullable()->index();
            $table->string('usage_type', 80)->index();
            $table->string('model', 120)->nullable()->index();
            $table->string('source', 80)->nullable()->index();
            $table->string('external_id', 160)->nullable();
            $table->string('unit', 40)->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('credits', 14, 4)->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->boolean('is_estimate')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at')->useCurrent()->index();
            $table->timestamps();
            $table->unique(['provider', 'usage_type', 'external_id']);
            $table->index(['user_id', 'recorded_at']);
            $table->index(['provider', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bean_usage_records');
    }
};
