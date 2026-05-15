<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspace_memberships') && ! Schema::hasColumn('workspace_memberships', 'metadata')) {
            Schema::table('workspace_memberships', function (Blueprint $table): void {
                $table->json('metadata')->nullable()->after('accepted_at');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'default_workspace_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('default_workspace_id')->nullable()->after('onboard_complete')->constrained('workspaces')->nullOnDelete();
            });
        }

        if (Schema::hasTable('agent_profiles') && Schema::hasColumn('agent_profiles', 'workspace_id')) {
            if (DB::getDriverName() === 'mysql') {
                try {
                    DB::statement('ALTER TABLE agent_profiles DROP FOREIGN KEY agent_profiles_user_id_foreign');
                } catch (Throwable) {
                    // The foreign key may already have been dropped on a partially migrated database.
                }

                try {
                    DB::statement('ALTER TABLE agent_profiles DROP INDEX agent_profiles_user_id_unique');
                } catch (Throwable) {
                    // The legacy unique index may already be gone.
                }

                try {
                    DB::statement('ALTER TABLE agent_profiles ADD INDEX agent_profiles_user_id_index (user_id)');
                } catch (Throwable) {
                    // A replacement user_id index already exists.
                }

                try {
                    DB::statement('ALTER TABLE agent_profiles ADD CONSTRAINT agent_profiles_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
                } catch (Throwable) {
                    // The foreign key already exists.
                }
            } else {
                Schema::table('agent_profiles', function (Blueprint $table): void {
                    try {
                        $table->dropUnique('agent_profiles_user_id_unique');
                    } catch (Throwable) {
                        // Some test databases may not expose the legacy unique index name.
                    }
                });
            }

            Schema::table('agent_profiles', function (Blueprint $table): void {
                try {
                    $table->unique('workspace_id', 'agent_profiles_workspace_id_unique');
                } catch (Throwable) {
                    // The workspace unique index already exists.
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'default_workspace_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('default_workspace_id');
            });
        }

        if (Schema::hasTable('workspace_memberships') && Schema::hasColumn('workspace_memberships', 'metadata')) {
            Schema::table('workspace_memberships', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }
    }
};
