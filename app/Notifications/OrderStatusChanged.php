<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChanged extends Notification
{
    public function __construct(private readonly Order $order) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $status = $this->order->status->name;

        return new DatabaseMessage([
            'type' => 'order_status_changed',
            'title' => 'Order '.ucwords(str_replace('_', ' ', $status)),
            'body' => "Your order {$this->order->order_number} is now {$status}.",
            'action_url' => null,
            'related_type' => 'order',
            'related_id' => $this->order->id,
        ]);
    }
}
