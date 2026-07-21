<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sticky_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('note_date');
            $table->text('content');
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'note_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sticky_notes');
    }
};
