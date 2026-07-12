<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_turns', function (Blueprint $table): void {
            $table->id();
            $table->string('turn_id', 120)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_message_id')->nullable()->unique()->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('final_assistant_message_id')->nullable()->unique()->constrained('conversation_messages')->nullOnDelete();
            $table->string('source', 40)->default('browser_voice_v2');
            $table->string('client_kind', 40)->default('browser_voice');
            $table->text('transcript');
            $table->text('sanitized_transcript');
            $table->string('lane', 40);
            $table->string('handler', 120);
            $table->string('state', 40)->default('accepted')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key', 120);
            $table->boolean('acknowledgement_required')->default(false);
            $table->text('acknowledgement_text')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('first_progress_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamp('final_delivered_at')->nullable();
            $table->timestamp('hard_deadline_at')->nullable();
            $table->timestamp('no_progress_deadline_at')->nullable();
            $table->string('failure_category', 80)->nullable();
            $table->text('internal_failure_detail')->nullable();
            $table->text('user_facing_failure_text')->nullable();
            $table->string('side_effect_status', 40)->default('none');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'voice_turns_user_idempotency_unique');
            $table->index(['conversation_session_id', 'state'], 'voice_turns_session_state_index');
            $table->index(['user_id', 'state'], 'voice_turns_user_state_index');
            $table->index(['state', 'hard_deadline_at'], 'voice_turns_hard_deadline_index');
            $table->index(['state', 'no_progress_deadline_at'], 'voice_turns_progress_deadline_index');
        });

        Schema::create('voice_turn_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_turn_id')->constrained('voice_turns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 100);
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40)->nullable();
            $table->unsignedInteger('version');
            $table->string('source', 80)->default('server');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['voice_turn_id', 'sequence'], 'voice_turn_events_turn_sequence_unique');
            $table->index(['conversation_session_id', 'id'], 'voice_turn_events_session_cursor_index');
            $table->index(['voice_turn_id', 'id'], 'voice_turn_events_turn_cursor_index');
        });

        Schema::table('assistant_runs', function (Blueprint $table): void {
            $table->foreignId('voice_turn_id')->nullable()->after('id')->constrained('voice_turns')->nullOnDelete();
            $table->string('lane', 40)->nullable()->after('source');
            $table->string('handler', 120)->nullable()->after('lane');
            $table->string('label', 180)->nullable()->after('handler');
            $table->integer('priority')->default(0)->after('label');
            $table->string('resource_lock_key', 180)->nullable()->after('priority');
            $table->string('idempotency_key', 160)->nullable()->after('resource_lock_key');
            $table->timestamp('hard_deadline_at')->nullable()->after('idempotency_key');
            $table->timestamp('last_progress_at')->nullable()->after('hard_deadline_at');
            $table->timestamp('dispatch_requested_at')->nullable()->after('last_progress_at');

            $table->unique(['voice_turn_id', 'idempotency_key'], 'assistant_runs_voice_turn_idempotency_unique');
            $table->index(['conversation_session_id', 'status', 'priority'], 'assistant_runs_voice_capacity_index');
            $table->index(['resource_lock_key', 'status'], 'assistant_runs_resource_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_runs', function (Blueprint $table): void {
            $table->dropUnique('assistant_runs_voice_turn_idempotency_unique');
            $table->dropIndex('assistant_runs_voice_capacity_index');
            $table->dropIndex('assistant_runs_resource_status_index');
            $table->dropConstrainedForeignId('voice_turn_id');
            $table->dropColumn([
                'lane',
                'handler',
                'label',
                'priority',
                'resource_lock_key',
                'idempotency_key',
                'hard_deadline_at',
                'last_progress_at',
                'dispatch_requested_at',
            ]);
        });

        Schema::dropIfExists('voice_turn_events');
        Schema::dropIfExists('voice_turns');
    }
};
