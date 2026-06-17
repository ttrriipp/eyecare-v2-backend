<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class NewOrderRequest extends Notification
{
    public function __construct(private readonly Order $order) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'new_order_request',
            'title' => 'New Order Request',
            'body' => "{$this->order->customer->name} submitted order {$this->order->order_number}.",
            'action_url' => '/admin/orders/'.$this->order->id.'/edit',
            'related_type' => 'order',
            'related_id' => $this->order->id,
        ]);
    }
}
