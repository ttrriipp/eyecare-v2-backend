<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use Filament\Notifications\Notification as FilamentNotification;
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

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->iconColor('info')
            ->title('New Message')
            ->body("{$this->message->sender->name} sent a message.")
            ->getDatabaseMessage();
    }
}
