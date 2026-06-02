<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_notification_device_tokens')) {
            $this->repairPartiallyCreatedTable();

            return;
        }

        Schema::create('push_notification_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 32)->nullable();
            $table->string('device_id', 255)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notification_device_tokens');
    }

    private function repairPartiallyCreatedTable(): void
    {
        if (! Schema::hasColumn('push_notification_device_tokens', 'token_hash')) {
            Schema::table('push_notification_device_tokens', function (Blueprint $table): void {
                $table->char('token_hash', 64)->nullable()->after('token');
            });
        }

        DB::table('push_notification_device_tokens')
            ->whereNull('token_hash')
            ->orderBy('id')
            ->select(['id', 'token'])
            ->chunkById(100, function ($tokens): void {
                foreach ($tokens as $token) {
                    DB::table('push_notification_device_tokens')
                        ->where('id', $token->id)
                        ->update(['token_hash' => hash('sha256', (string) $token->token)]);
                }
            });

        try {
            Schema::table('push_notification_device_tokens', function (Blueprint $table): void {
                $table->unique('token_hash');
            });
        } catch (Throwable) {
            // The failed deployment may already have reached this repair step.
        }
    }
};
