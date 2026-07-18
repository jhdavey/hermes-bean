<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bean_quality_traces', 'date_scope') && ! Schema::hasColumn('bean_quality_traces', 'time_label')) {
            Schema::table('bean_quality_traces', function (Blueprint $table): void {
                $table->renameColumn('date_scope', 'time_label');
            });
        } elseif (! Schema::hasColumn('bean_quality_traces', 'time_label')) {
            Schema::table('bean_quality_traces', function (Blueprint $table): void {
                $table->string('time_label')->nullable()->index();
            });
        }

        if (Schema::hasColumn('bean_quality_traces', 'date_scope') && Schema::hasColumn('bean_quality_traces', 'time_label')) {
            Schema::table('bean_quality_traces', function (Blueprint $table): void {
                $table->dropColumn('date_scope');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bean_quality_traces', 'time_label') && ! Schema::hasColumn('bean_quality_traces', 'date_scope')) {
            Schema::table('bean_quality_traces', function (Blueprint $table): void {
                $table->renameColumn('time_label', 'date_scope');
            });
        }
    }
};
