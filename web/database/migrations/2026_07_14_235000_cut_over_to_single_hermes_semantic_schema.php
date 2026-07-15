<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring databases that already ran the pre-cutover migrations onto the
     * single Hermes semantic runtime schema. The historical migrations describe
     * clean installs; this migration is the only production upgrade bridge.
     */
    public function up(): void
    {
        $this->cutOverConversationSessions();
        $this->removeRetiredAgentRuntimeState();
        $this->cutOverUsageRouting();
        $this->cutOverAssistantRunIdentity();
        $this->cutOverMemoryEventIdentity();
        $this->cutOverVoiceLifecycleTimestamps();

        Schema::dropIfExists('admin_command_runs');
    }

    public function down(): void
    {
        // This clean cutover intentionally has no legacy-runtime rollback path.
    }

    private function cutOverConversationSessions(): void
    {
        if (! Schema::hasTable('conversation_sessions')) {
            return;
        }

        if (! Schema::hasColumn('conversation_sessions', 'session_kind')) {
            Schema::table('conversation_sessions', function (Blueprint $table): void {
                $table->enum('session_kind', ['conversation', 'onboarding'])
                    ->default('conversation');
            });
        }

        if (! Schema::hasIndex('conversation_sessions', 'conversation_sessions_session_kind_index')) {
            Schema::table('conversation_sessions', function (Blueprint $table): void {
                $table->index('session_kind');
            });
        }

        if (Schema::hasColumn('conversation_sessions', 'runtime_mode')) {
            Schema::table('conversation_sessions', function (Blueprint $table): void {
                $table->dropColumn('runtime_mode');
            });
        }
    }

    private function removeRetiredAgentRuntimeState(): void
    {
        if (! Schema::hasTable('agent_profiles')) {
            return;
        }

        if (Schema::hasIndex('agent_profiles', 'agent_profiles_router_mode_index')) {
            Schema::table('agent_profiles', function (Blueprint $table): void {
                $table->dropIndex('agent_profiles_router_mode_index');
            });
        }

        $retiredColumns = array_values(array_filter([
            'provider',
            'model',
            'router_mode',
            'runtime_home',
            'tool_policy',
            'approval_policy',
        ], fn (string $column): bool => Schema::hasColumn('agent_profiles', $column)));

        if ($retiredColumns !== []) {
            Schema::table('agent_profiles', function (Blueprint $table) use ($retiredColumns): void {
                $table->dropColumn($retiredColumns);
            });
        }
    }

    private function cutOverUsageRouting(): void
    {
        if (! Schema::hasTable('ai_usage_logs') || ! Schema::hasColumn('ai_usage_logs', 'route_tier')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->string('route_tier')
                ->default('semantic_interpretation')
                ->change();
        });
    }

    private function cutOverAssistantRunIdentity(): void
    {
        if (! Schema::hasTable('assistant_runs')) {
            return;
        }

        if (! Schema::hasColumn('assistant_runs', 'client_request_id')) {
            Schema::table('assistant_runs', function (Blueprint $table): void {
                $table->string('client_request_id', 120)->nullable();
            });
        }

        if (! Schema::hasColumn('assistant_runs', 'request_fingerprint')) {
            Schema::table('assistant_runs', function (Blueprint $table): void {
                $table->string('request_fingerprint', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('assistant_runs', 'execution_generation')) {
            Schema::table('assistant_runs', function (Blueprint $table): void {
                $table->unsignedInteger('execution_generation')->default(0);
            });
        }

        if (! Schema::hasColumn('assistant_runs', 'queued_at')) {
            Schema::table('assistant_runs', function (Blueprint $table): void {
                $table->timestamp('queued_at', 6)->nullable();
            });
        }

        if (! Schema::hasIndex('assistant_runs', 'assistant_runs_session_client_request_unique')) {
            $this->removeDuplicateAssistantRunRequestIds();

            Schema::table('assistant_runs', function (Blueprint $table): void {
                $table->unique(
                    ['conversation_session_id', 'client_request_id'],
                    'assistant_runs_session_client_request_unique',
                );
            });
        }

        $this->ensureMicrosecondTimestamps('assistant_runs', [
            'queued_at',
            'started_at',
            'completed_at',
            'cancelled_at',
            'created_at',
            'updated_at',
            'hard_deadline_at',
            'last_progress_at',
            'dispatch_requested_at',
        ]);
    }

    private function removeDuplicateAssistantRunRequestIds(): void
    {
        $duplicates = DB::table('assistant_runs')
            ->select(['conversation_session_id', 'client_request_id'])
            ->whereNotNull('client_request_id')
            ->groupBy('conversation_session_id', 'client_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateIds = DB::table('assistant_runs')
                ->where('conversation_session_id', $duplicate->conversation_session_id)
                ->where('client_request_id', $duplicate->client_request_id)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1)
                ->all();

            if ($duplicateIds !== []) {
                DB::table('assistant_runs')
                    ->whereIn('id', $duplicateIds)
                    ->update([
                        'client_request_id' => null,
                        'request_fingerprint' => null,
                    ]);
            }
        }
    }

    private function cutOverMemoryEventIdentity(): void
    {
        if (! Schema::hasTable('memory_events')
            || ! Schema::hasColumn('memory_events', 'conversation_message_id')
            || ! Schema::hasColumn('memory_events', 'event_type')
            || Schema::hasIndex('memory_events', 'memory_events_message_type_unique')) {
            return;
        }

        $duplicates = DB::table('memory_events')
            ->select(['conversation_message_id', 'event_type'])
            ->whereNotNull('conversation_message_id')
            ->groupBy('conversation_message_id', 'event_type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateIds = DB::table('memory_events')
                ->where('conversation_message_id', $duplicate->conversation_message_id)
                ->where('event_type', $duplicate->event_type)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1)
                ->all();

            if ($duplicateIds !== []) {
                DB::table('memory_events')->whereIn('id', $duplicateIds)->delete();
            }
        }

        Schema::table('memory_events', function (Blueprint $table): void {
            $table->unique(
                ['conversation_message_id', 'event_type'],
                'memory_events_message_type_unique',
            );
        });
    }

    private function cutOverVoiceLifecycleTimestamps(): void
    {
        if (Schema::hasTable('voice_turns')) {
            $retiredColumns = array_values(array_filter(
                ['lane', 'handler'],
                fn (string $column): bool => Schema::hasColumn('voice_turns', $column),
            ));

            if ($retiredColumns !== []) {
                Schema::table('voice_turns', function (Blueprint $table) use ($retiredColumns): void {
                    $table->dropColumn($retiredColumns);
                });
            }

            $this->ensureMicrosecondTimestamps('voice_turns', [
                'acknowledged_at',
                'accepted_at',
                'started_at',
                'first_progress_at',
                'terminal_at',
                'hard_deadline_at',
                'no_progress_deadline_at',
                'created_at',
                'updated_at',
            ]);
        }

        $this->ensureMicrosecondTimestamps('voice_turn_events', [
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * SQLite timestamps are affinity-backed text values and retain fractional
     * seconds without a precision declaration. MySQL needs an explicit (6).
     *
     * @param  list<string>  $columns
     */
    private function ensureMicrosecondTimestamps(string $tableName, array $columns): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable($tableName)) {
            return;
        }

        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns): void {
            foreach ($existingColumns as $column) {
                $table->timestamp($column, 6)->nullable()->change();
            }
        });
    }
};
