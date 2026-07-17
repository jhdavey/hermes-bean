<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bean_quality_traces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode')->default('text')->index();
            $table->string('intent')->nullable()->index();
            $table->json('actions')->nullable();
            $table->string('date_scope')->nullable()->index();
            $table->unsignedInteger('tool_results_count')->default(0);
            $table->text('user_message')->nullable();
            $table->text('assistant_answer')->nullable();
            $table->json('quality_flags')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('voice')->default(false)->index();
            $table->timestamps();

            $table->index(['intent', 'created_at']);
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bean_quality_traces');
    }
};
