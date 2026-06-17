<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class NewMessageReceived extends Notification
{
    public function __construct(
        private readonly Message $message,
        private readonly Conversation $conversation,
    ) {}

    /** @return string[] */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'type' => 'new_message_received',
            'title' => 'New Message',
            'body' => "{$this->message->sender->name} sent a message.",
            'action_url' => '/admin/conversations/'.$this->conversation->id,
            'related_type' => 'conversation',
            'related_id' => $this->conversation->id,
        ]);
    }
}
