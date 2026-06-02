<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('issue_reports') && Schema::hasColumn('issue_reports', 'beta_user_id')) {
            Schema::table('issue_reports', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('beta_user_id');
            });
        }

        Schema::dropIfExists('beta_users');
    }

    public function down(): void
    {
        // beta_users was intentionally retired in favor of early_access_signups.
    }
};
