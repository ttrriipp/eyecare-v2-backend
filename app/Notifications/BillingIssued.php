<?php

namespace App\Notifications;

use App\Models\Billing;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class BillingIssued extends Notification
{
    public function __construct(private readonly Billing $billing) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->icon('heroicon-o-banknotes')
            ->iconColor('info')
            ->title('Billing Issued')
            ->body("Billing {$this->billing->billing_number} has been issued for ₱{$this->billing->total_amount}.")
            ->getDatabaseMessage();
    }
}
