<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['purchaser_user_id', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_invoice_id', 'plan', 'billing_interval', 'amount_paid_cents', 'currency', 'sent_count', 'notification_attempted_at'])]
class PaidSubscriptionPurchaseNotification extends Model
{
    protected function casts(): array
    {
        return [
            'notification_attempted_at' => 'datetime',
        ];
    }
}
