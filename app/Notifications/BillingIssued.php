<?php

namespace App\Notifications;

use App\Models\Billing;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class BillingIssued extends Notification
{
    public function __construct(private readonly Billing $billing) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'billing_issued',
            'title' => 'Billing Issued',
            'body' => "Billing {$this->billing->billing_number} has been issued for ₱{$this->billing->total_amount}.",
            'action_url' => null,
            'related_type' => 'billing',
            'related_id' => $this->billing->id,
        ]);
    }
}
