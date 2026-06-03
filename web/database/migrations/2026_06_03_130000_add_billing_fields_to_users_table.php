<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->after('subscription_tier')->index();
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id')->index();
            $table->string('stripe_subscription_item_id')->nullable()->after('stripe_subscription_id');
            $table->string('stripe_price_id')->nullable()->after('stripe_subscription_item_id');
            $table->string('subscription_status')->nullable()->after('stripe_price_id');
            $table->timestamp('subscription_current_period_end')->nullable()->after('subscription_status');
            $table->timestamp('subscription_trial_ends_at')->nullable()->after('subscription_current_period_end');
            $table->boolean('subscription_cancel_at_period_end')->default(false)->after('subscription_trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_subscription_item_id',
                'stripe_price_id',
                'subscription_status',
                'subscription_current_period_end',
                'subscription_trial_ends_at',
                'subscription_cancel_at_period_end',
            ]);
        });
    }
};
