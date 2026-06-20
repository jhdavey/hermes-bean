<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('note_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'sort_order']);
            $table->index(['user_id', 'workspace_id']);
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('note_folder_id')->nullable()->constrained('note_folders')->nullOnDelete();
            $table->string('title')->default('New Note');
            $table->longText('body_html')->nullable();
            $table->longText('plain_text')->nullable();
            $table->json('body_delta')->nullable();
            $table->boolean('is_pinned')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_pinned', 'updated_at']);
            $table->index(['user_id', 'workspace_id']);
            $table->index(['note_folder_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('note_folders');
    }
};
