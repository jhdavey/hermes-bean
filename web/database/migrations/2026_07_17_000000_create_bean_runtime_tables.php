<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bean_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('bean_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_session_id')->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('mode')->default('text');
            $table->string('model')->nullable();
            $table->text('input')->nullable();
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['bean_session_id', 'id']);
        });

        Schema::create('bean_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_session_id')->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->index();
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['bean_session_id', 'id']);
        });

        Schema::create('bean_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_session_id')->nullable()->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('label')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['bean_session_id', 'id']);
        });

        Schema::create('bean_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_run_id')->constrained('bean_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->json('arguments')->nullable();
            $table->string('status')->default('queued')->index();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->boolean('requires_confirmation')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bean_confirmation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bean_session_id')->constrained('bean_sessions')->cascadeOnDelete();
            $table->foreignId('bean_run_id')->nullable()->constrained('bean_runs')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->json('arguments')->nullable();
            $table->string('summary');
            $table->string('status')->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bean_confirmation_requests');
        Schema::dropIfExists('bean_tool_calls');
        Schema::dropIfExists('bean_activity_events');
        Schema::dropIfExists('bean_messages');
        Schema::dropIfExists('bean_runs');
        Schema::dropIfExists('bean_sessions');
    }
};
