<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_access_signups', function (Blueprint $table): void {
            $table->string('requested_plan')->nullable()->after('use_case')->index();
        });
    }

    public function down(): void
    {
        Schema::table('early_access_signups', function (Blueprint $table): void {
            $table->dropColumn('requested_plan');
        });
    }
};
