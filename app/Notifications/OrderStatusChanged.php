<?php

namespace App\Notifications;

use App\Models\Order;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class OrderStatusChanged extends Notification
{
    public function __construct(private readonly Order $order) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $status = $this->order->status->name;

        return FilamentNotification::make()
            ->icon('heroicon-o-shopping-bag')
            ->iconColor(match ($status) {
                'confirmed', 'completed' => 'success',
                'cancelled' => 'danger',
                default => 'info',
            })
            ->title('Order '.ucwords(str_replace('_', ' ', $status)))
            ->body("Your order {$this->order->order_number} is now {$status}.")
            ->getDatabaseMessage();
    }
}
