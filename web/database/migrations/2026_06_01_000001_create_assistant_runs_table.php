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
            $table->string('source')->default('http')->index();
            $table->string('status')->default('queued')->index();
            $table->text('input');
            $table->json('metadata')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_runs');
    }
};
