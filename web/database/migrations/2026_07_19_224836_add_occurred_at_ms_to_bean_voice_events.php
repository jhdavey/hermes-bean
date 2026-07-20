<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bean_voice_events', 'occurred_at_ms')) {
            return;
        }

        Schema::table('bean_voice_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('occurred_at_ms')->nullable()->after('occurred_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bean_voice_events', 'occurred_at_ms')) {
            return;
        }

        Schema::table('bean_voice_events', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at_ms']);
            $table->dropColumn('occurred_at_ms');
        });
    }
};
