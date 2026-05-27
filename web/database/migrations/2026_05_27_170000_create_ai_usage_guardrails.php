<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('onboard_complete')->index();
            $table->string('subscription_tier')->default('free')->after('is_admin')->index();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('provider')->nullable()->index();
            $table->string('model')->index();
            $table->string('route_tier')->default('complex')->index();
            $table->string('status')->default('completed')->index();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->json('action_types')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('ai_usage_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_type')->index();
            $table->unsignedBigInteger('scope_id')->nullable()->index();
            $table->string('alert_type')->index();
            $table->string('severity')->default('warning')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('threshold_value', 14, 4)->nullable();
            $table->decimal('observed_value', 14, 4)->nullable();
            $table->string('message');
            $table->json('metadata')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['alert_type', 'scope_type', 'scope_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_alerts');
        Schema::dropIfExists('ai_usage_logs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_admin', 'subscription_tier']);
        });
    }
};
