<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 6)->unique();
            $table->unsignedSmallInteger('months_free_base')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('base_access_expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('base_comp_expires_at')->nullable()->after('subscription_current_period_end');
            $table->foreignId('base_comp_source_coupon_code_id')
                ->nullable()
                ->after('base_comp_expires_at')
                ->constrained('coupon_codes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('base_comp_source_coupon_code_id');
            $table->dropColumn('base_comp_expires_at');
        });

        Schema::dropIfExists('coupon_codes');
    }
};
