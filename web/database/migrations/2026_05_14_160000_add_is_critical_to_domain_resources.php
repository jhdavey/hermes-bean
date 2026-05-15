<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('is_critical')->default(false)->after('color');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->boolean('is_critical')->default(false)->after('color');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->boolean('is_critical')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('is_critical');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn('is_critical');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn('is_critical');
        });
    }
};
