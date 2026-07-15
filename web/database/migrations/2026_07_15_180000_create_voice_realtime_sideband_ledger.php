<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyVoiceColumns = collect(['transcript', 'sanitized_transcript', 'final_delivered_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('voice_turns', $column))
            ->values()
            ->all();
        if ($legacyVoiceColumns !== []) {
            Schema::table('voice_turns', function (Blueprint $table) use ($legacyVoiceColumns): void {
                $table->dropColumn($legacyVoiceColumns);
            });
        }

        Schema::create('voice_realtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider_call_id', 191)->nullable()->unique();
            $table->string('provider_model', 100);
            $table->string('voice', 80);
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedInteger('controller_generation')->default(0);
            $table->string('lease_owner', 191)->nullable()->index();
            $table->timestamp('lease_expires_at', 6)->nullable()->index();
            $table->unsignedInteger('connect_attempts')->default(0);
            $table->unsignedInteger('reconnect_count')->default(0);
            $table->timestamp('reconnect_not_before_at', 6)->nullable()->index();
            $table->timestamp('sideband_connected_at', 6)->nullable();
            $table->timestamp('last_heartbeat_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->string('failure_category', 100)->nullable();
            $table->text('failure_detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->index(
                ['user_id', 'conversation_session_id', 'status'],
                'voice_realtime_sessions_owner_state_index',
            );
            $table->index(
                ['status', 'lease_expires_at'],
                'voice_realtime_sessions_lease_scan_index',
            );
        });

        Schema::table('voice_turns', function (Blueprint $table): void {
            $table->foreignId('realtime_session_id')
                ->nullable()
                ->after('conversation_session_id')
                ->constrained('voice_realtime_sessions')
                ->nullOnDelete();
            $table->string('provider_input_item_id', 191)->nullable()->after('realtime_session_id');
            $table->text('semantic_input')->nullable()->after('provider_input_item_id');
            $table->string('display_mode', 40)->default('voice_only')->after('client_kind');

            $table->index(
                ['realtime_session_id', 'provider_input_item_id'],
                'voice_turns_realtime_input_item_index',
            );
            $table->index(
                ['conversation_session_id', 'display_mode'],
                'voice_turns_session_display_mode_index',
            );
        });

        Schema::create('voice_realtime_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_realtime_session_id')
                ->constrained('voice_realtime_sessions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider_event_id', 191);
            $table->string('event_type', 120)->index();
            $table->string('provider_input_item_id', 191)->nullable()->index();
            $table->string('provider_response_id', 191)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('received_at', 6);
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->string('processing_lease_owner', 191)->nullable();
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('processed_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['voice_realtime_session_id', 'provider_event_id'],
                'voice_realtime_events_session_provider_unique',
            );
            $table->index(
                ['conversation_session_id', 'id'],
                'voice_realtime_events_conversation_cursor_index',
            );
            $table->index(
                ['voice_realtime_session_id', 'processed_at', 'next_attempt_at'],
                'voice_realtime_events_recovery_index',
            );
        });

        Schema::create('voice_realtime_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voice_realtime_session_id')
                ->constrained('voice_realtime_sessions')
                ->cascadeOnDelete();
            $table->foreignId('voice_turn_id')->nullable()->constrained('voice_turns')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->constrained()->cascadeOnDelete();
            $table->string('command_id', 191);
            $table->string('command_type', 100)->index();
            $table->string('purpose', 80)->nullable();
            $table->string('speech_item_id', 191)->nullable()->index();
            $table->unsignedInteger('controller_generation')->default(0);
            $table->char('approved_text_hash', 64)->nullable();
            $table->json('payload');
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('sending_lease_owner', 191)->nullable()->index();
            $table->string('provider_response_id', 191)->nullable()->index();
            $table->timestamp('available_at', 6);
            $table->timestamp('sending_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['voice_realtime_session_id', 'command_id'],
                'voice_realtime_commands_session_command_unique',
            );
            $table->index(
                ['voice_realtime_session_id', 'status', 'available_at', 'id'],
                'voice_realtime_commands_outbox_index',
            );
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('origin', 40)->default('typed_chat')->after('role');
            $table->string('display_mode', 40)->default('chat')->after('origin');

            $table->index(
                ['conversation_session_id', 'display_mode', 'id'],
                'conversation_messages_session_display_index',
            );
            $table->index(
                ['origin', 'display_mode'],
                'conversation_messages_origin_display_index',
            );
        });

        DB::table('voice_turns')->update(['display_mode' => 'voice_only']);

        DB::table('voice_turns')
            ->select(['user_message_id', 'final_assistant_message_id'])
            ->orderBy('id')
            ->chunk(500, function ($turns): void {
                $messageIds = collect($turns)
                    ->flatMap(fn (object $turn): array => [
                        $turn->user_message_id,
                        $turn->final_assistant_message_id,
                    ])
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($messageIds !== []) {
                    DB::table('conversation_messages')
                        ->whereIn('id', $messageIds)
                        ->update([
                            'origin' => 'spoken_voice',
                            'display_mode' => 'voice_only',
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_messages_session_display_index');
            $table->dropIndex('conversation_messages_origin_display_index');
            $table->dropColumn(['origin', 'display_mode']);
        });

        Schema::dropIfExists('voice_realtime_commands');
        Schema::dropIfExists('voice_realtime_events');

        Schema::table('voice_turns', function (Blueprint $table): void {
            $table->dropIndex('voice_turns_realtime_input_item_index');
            $table->dropIndex('voice_turns_session_display_mode_index');
            $table->dropConstrainedForeignId('realtime_session_id');
            $table->dropColumn(['provider_input_item_id', 'semantic_input', 'display_mode']);
        });

        Schema::dropIfExists('voice_realtime_sessions');
    }
};
