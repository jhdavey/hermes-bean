<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_customer_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('billing_type')->default('monthly')->index();
            $table->decimal('monthly_rate_usd', 12, 2)->nullable();
            $table->decimal('usage_rate_usd', 12, 6)->nullable();
            $table->json('limits')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_customer_limits');
    }
};
