<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->string('status')->default('active')->index();
            $table->string('provider')->default('openai');
            $table->string('model')->default('gpt-5.5');
            $table->string('router_mode')->default('fixed')->index();
            $table->string('runtime_home')->nullable();
            $table->json('settings')->nullable();
            $table->json('tool_policy')->nullable();
            $table->json('approval_policy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profiles');
    }
};
