<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('api');
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        foreach ([
            'conversation_sessions',
            'conversation_messages',
            'activity_events',
            'tasks',
            'reminders',
            'calendar_events',
            'approvals',
            'blockers',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('user_id')->after('id')->nullable()->constrained()->cascadeOnDelete();
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'blockers',
            'approvals',
            'calendar_events',
            'reminders',
            'tasks',
            'activity_events',
            'conversation_messages',
            'conversation_sessions',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        Schema::dropIfExists('personal_access_tokens');
    }
};
