<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
