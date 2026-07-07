<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->string('request_type')->default('agent')->after('route_tier')->index();
            $table->unsignedInteger('tool_call_count')->default(0)->after('total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'request_type',
                'tool_call_count',
            ]);
        });
    }
};
