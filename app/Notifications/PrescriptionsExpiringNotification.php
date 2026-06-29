<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PrescriptionsExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $count,
        public string $summary,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "{$this->count} prescription(s) expiring soon",
            'body' => $this->summary,
            'icon' => 'heroicon-o-exclamation-triangle',
            'iconColor' => 'warning',
        ];
    }
}
