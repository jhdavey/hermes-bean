<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('memory_items')) {
            Schema::create('memory_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 50)->index();
                $table->string('status', 50)->default('active')->index();
                $table->string('visibility', 50)->default('workspace')->index();
                $table->string('title')->nullable();
                $table->text('content');
                $table->text('summary')->nullable();
                $table->unsignedTinyInteger('confidence')->default(70)->index();
                $table->unsignedSmallInteger('importance')->default(50)->index();
                $table->string('source_type', 80)->nullable()->index();
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'workspace_id', 'status', 'type']);
                $table->index(['user_id', 'status', 'importance']);
                $table->index(['workspace_id', 'status', 'updated_at']);
            });
        }

        if (! Schema::hasTable('memory_events')) {
            Schema::create('memory_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
                $table->foreignId('assistant_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
                $table->string('event_type', 80)->index();
                $table->string('status', 50)->default('pending')->index();
                $table->text('content')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamps();

                $table->index(['user_id', 'workspace_id', 'event_type']);
                $table->index(['conversation_session_id', 'conversation_message_id'], 'memory_events_session_message_idx');
            });
        }

        if (! Schema::hasTable('memory_summaries')) {
            Schema::create('memory_summaries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('summary_type', 50)->index();
                $table->string('period_key', 80)->nullable()->index();
                $table->string('title')->nullable();
                $table->text('summary');
                $table->timestamp('starts_at')->nullable()->index();
                $table->timestamp('ends_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'workspace_id', 'summary_type', 'period_key'], 'memory_summaries_scope_unique');
            });
        }

        if (! Schema::hasTable('memory_links')) {
            Schema::create('memory_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('memory_item_id')->constrained()->cascadeOnDelete();
                $table->string('linkable_type', 80);
                $table->unsignedBigInteger('linkable_id');
                $table->string('relationship', 80)->default('related');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['linkable_type', 'linkable_id']);
                $table->unique(['memory_item_id', 'linkable_type', 'linkable_id', 'relationship'], 'memory_links_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_links');
        Schema::dropIfExists('memory_summaries');
        Schema::dropIfExists('memory_events');
        Schema::dropIfExists('memory_items');
    }
};
