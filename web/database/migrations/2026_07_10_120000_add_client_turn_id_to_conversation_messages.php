<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('client_turn_id', 120)->nullable()->after('conversation_session_id');
            $table->unique(
                ['conversation_session_id', 'client_turn_id', 'role'],
                'conversation_messages_client_turn_role_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropUnique('conversation_messages_client_turn_role_unique');
            $table->dropColumn('client_turn_id');
        });
    }
};
