<?php

namespace App\Notifications;

use App\Models\Order;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class NewOrderRequest extends Notification
{
    public function __construct(private readonly Order $order) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->icon('heroicon-o-shopping-cart')
            ->iconColor('primary')
            ->title('New Order Request')
            ->body("{$this->order->customer->name} submitted order {$this->order->order_number}.")
            ->getDatabaseMessage();
    }
}
