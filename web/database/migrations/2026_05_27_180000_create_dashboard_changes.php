<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('resource_type')->index();
            $table->string('action')->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_changes');
    }
};
