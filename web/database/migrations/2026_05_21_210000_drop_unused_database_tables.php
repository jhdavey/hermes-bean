<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('scheduler_job_records');
        Schema::dropIfExists('cache_locks');
    }

    public function down(): void
    {
        // Removed pre-MVP tables are intentionally not recreated.
    }
};
