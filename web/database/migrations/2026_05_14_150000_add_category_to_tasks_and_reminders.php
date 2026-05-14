<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('category', 80)->nullable()->after('notes');
            $table->string('color', 20)->nullable()->after('category');
        });

        Schema::table('reminders', function (Blueprint $table): void {
            $table->string('category', 80)->nullable()->after('notes');
            $table->string('color', 20)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropColumn(['category', 'color']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['category', 'color']);
        });
    }
};
