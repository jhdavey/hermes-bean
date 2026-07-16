<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('type')->default('todo')->index();
                $table->string('status')->default('open')->index();
                $table->text('notes')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminders')) {
            Schema::create('reminders', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->text('notes')->nullable();
                $table->timestamp('remind_at');
                $table->string('status')->default('scheduled')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->string('status')->default('scheduled')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('tasks');
    }
};
