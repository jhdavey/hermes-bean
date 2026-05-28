<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_profiles')
            ->where('provider', 'openrouter')
            ->update(['provider' => 'openai']);
    }

    public function down(): void
    {
        //
    }
};
