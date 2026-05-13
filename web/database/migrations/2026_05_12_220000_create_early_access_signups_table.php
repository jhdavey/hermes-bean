<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('early_access_signups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->text('use_case')->nullable();
            $table->string('source')->default('landing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_access_signups');
    }
};
