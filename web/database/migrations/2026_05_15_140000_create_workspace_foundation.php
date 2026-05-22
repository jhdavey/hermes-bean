<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table): void {
                $table->id();
                $table->string('type')->default('personal')->index();
                $table->string('name');
                $table->string('slug')->nullable()->index();
                $table->foreignId('personal_owner_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('active')->index();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workspace_memberships')) {
            Schema::create('workspace_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('role')->default('member')->index();
                $table->string('status')->default('active')->index();
                $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('invited_email')->nullable()->index();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'user_id']);
                $table->index(['user_id', 'status']);
                $table->index(['workspace_id', 'status']);
            });
        }

        if (! Schema::hasTable('workspace_item_links')) {
            Schema::create('workspace_item_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('source_workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('target_workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->string('source_type', 80);
                $table->unsignedBigInteger('source_id');
                $table->string('target_type', 80);
                $table->unsignedBigInteger('target_id');
                $table->string('link_type', 80);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['source_workspace_id', 'source_type', 'source_id'], 'wil_source_lookup_idx');
                $table->index(['target_workspace_id', 'target_type', 'target_id'], 'wil_target_lookup_idx');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE workspace_item_links MODIFY source_type VARCHAR(80) NOT NULL, MODIFY target_type VARCHAR(80) NOT NULL, MODIFY link_type VARCHAR(80) NOT NULL');
        }

        try {
            Schema::table('workspace_item_links', function (Blueprint $table): void {
                $table->unique([
                    'source_workspace_id',
                    'target_workspace_id',
                    'source_type',
                    'source_id',
                    'target_type',
                    'target_id',
                    'link_type',
                ], 'wil_idempotent_unique');
            });
        } catch (Throwable) {
            // The idempotency index already exists from a previous attempt.
        }

        if (! Schema::hasTable('workspace_google_calendar_mappings')) {
            Schema::create('workspace_google_calendar_mappings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->unsignedBigInteger('google_calendar_connection_id');
                $table->string('google_calendar_id');
                $table->string('sync_direction')->default('both');
                $table->boolean('is_default_export')->default(false);
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->foreign('google_calendar_connection_id', 'wgcm_connection_fk')
                    ->references('id')
                    ->on('google_calendar_connections')
                    ->cascadeOnDelete();
                $table->unique(['workspace_id', 'google_calendar_connection_id', 'google_calendar_id'], 'wgcm_workspace_connection_calendar_unique');
                $table->index(['workspace_id', 'is_default_export'], 'wgcm_default_export_idx');
            });
        }

        foreach ($this->workspaceScopedTables() as $tableName => $deleteBehavior) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'workspace_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($deleteBehavior): void {
                $column = $table->foreignId('workspace_id')->nullable()->after('id')->constrained('workspaces');
                $deleteBehavior === 'cascade' ? $column->cascadeOnDelete() : $column->nullOnDelete();
                $table->index('workspace_id');
            });
        }

        foreach (['tasks', 'reminders', 'calendar_events', 'conversation_sessions', 'activity_events'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'created_by_user_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
                $table->index('created_by_user_id');
            });
        }

        $this->backfillPersonalWorkspaces();
    }

    public function down(): void
    {
        foreach (array_reverse(['tasks', 'reminders', 'calendar_events', 'conversation_sessions', 'activity_events']) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'created_by_user_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by_user_id');
            });
        }

        foreach (array_reverse(array_keys($this->workspaceScopedTables())) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'workspace_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }

        Schema::dropIfExists('workspace_google_calendar_mappings');
        Schema::dropIfExists('workspace_item_links');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
    }

    /**
     * @return array<string, string>
     */
    private function workspaceScopedTables(): array
    {
        return [
            'agent_profiles' => 'null',
            'conversation_sessions' => 'null',
            'activity_events' => 'null',
            'tasks' => 'null',
            'reminders' => 'null',
            'calendar_events' => 'null',
            'approvals' => 'null',
            'blockers' => 'null',
            'event_categories' => 'null',
        ];
    }

    private function backfillPersonalWorkspaces(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $now = now();

        DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->each(function (object $user) use ($now): void {
            $workspace = DB::table('workspaces')->where('personal_owner_user_id', $user->id)->first();

            if (! $workspace) {
                $workspaceId = DB::table('workspaces')->insertGetId([
                    'type' => 'personal',
                    'name' => trim((string) ($user->name ?? '')) !== '' ? $user->name.' Personal Workspace' : 'Personal Workspace',
                    'slug' => 'personal-'.$user->id,
                    'personal_owner_user_id' => $user->id,
                    'created_by_user_id' => $user->id,
                    'status' => 'active',
                    'settings' => null,
                    'metadata' => json_encode(['backfilled_from' => 'workspace_foundation_migration'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $workspaceId = $workspace->id;
            }

            DB::table('workspace_memberships')->updateOrInsert(
                ['workspace_id' => $workspaceId, 'user_id' => $user->id],
                [
                    'role' => 'owner',
                    'status' => 'active',
                    'invited_by_user_id' => null,
                    'invited_email' => null,
                    'accepted_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $this->backfillUserRowsToWorkspace((int) $user->id, (int) $workspaceId);
        });
    }

    private function backfillUserRowsToWorkspace(int $userId, int $workspaceId): void
    {
        foreach (array_keys($this->workspaceScopedTables()) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'user_id')) {
                continue;
            }

            $updates = [];
            if (Schema::hasColumn($tableName, 'workspace_id')) {
                $updates['workspace_id'] = $workspaceId;
            }
            if (Schema::hasColumn($tableName, 'created_by_user_id')) {
                $updates['created_by_user_id'] = $userId;
            }

            if ($updates === []) {
                continue;
            }

            if (array_key_exists('workspace_id', $updates)) {
                DB::table($tableName)
                    ->where('user_id', $userId)
                    ->whereNull('workspace_id')
                    ->update(['workspace_id' => $workspaceId]);
            }

            if (array_key_exists('created_by_user_id', $updates)) {
                DB::table($tableName)
                    ->where('user_id', $userId)
                    ->whereNull('created_by_user_id')
                    ->update(['created_by_user_id' => $userId]);
            }
        }
    }
};
