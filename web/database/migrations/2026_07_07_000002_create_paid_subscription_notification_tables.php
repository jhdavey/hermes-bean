<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paid_subscription_notification_recipients')) {
            Schema::create('paid_subscription_notification_recipients', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->boolean('enabled')->default(true);
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }

        DB::table('paid_subscription_notification_recipients')->insertOrIgnore([
            'user_id' => 17,
            'enabled' => true,
            'notes' => 'Initial founder notification recipient.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('paid_subscription_purchase_notifications')) {
            Schema::create('paid_subscription_purchase_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('purchaser_user_id')->nullable()->index();
                $table->string('stripe_customer_id')->nullable()->index();
                $table->string('stripe_subscription_id')->unique();
                $table->string('stripe_invoice_id')->nullable()->unique();
                $table->string('plan', 32);
                $table->string('billing_interval', 16)->default('monthly');
                $table->unsignedInteger('amount_paid_cents');
                $table->string('currency', 8)->default('usd');
                $table->unsignedInteger('sent_count')->default(0);
                $table->timestamp('notification_attempted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_subscription_purchase_notifications');
        Schema::dropIfExists('paid_subscription_notification_recipients');
    }
};
