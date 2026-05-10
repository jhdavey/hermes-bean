<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('runtime_mode')->default('stub');
            $table->json('metadata')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('tool_name')->nullable();
            $table->string('status')->default('recorded');
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('todo')->index();
            $table->string('status')->default('open')->index();
            $table->text('notes')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestamp('remind_at');
            $table->string('status')->default('scheduled')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('scheduled')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('blockers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->string('status')->default('open')->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('scheduler_job_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('status')->default('queued')->index();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_job_records');
        Schema::dropIfExists('blockers');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversation_sessions');
    }
};
