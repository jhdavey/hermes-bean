<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('client_request_id', 120)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unsignedInteger('execution_generation')->default(0);
            $table->string('source')->default('http')->index();
            $table->string('status')->default('queued')->index();
            $table->text('input');
            $table->json('metadata')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('cancelled_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['conversation_session_id', 'status']);
            $table->unique(
                ['conversation_session_id', 'client_request_id'],
                'assistant_runs_session_client_request_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_runs');
    }
};
