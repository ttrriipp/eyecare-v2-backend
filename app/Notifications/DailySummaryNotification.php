<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DailySummaryNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $completedAppointments,
        public string $revenue,
        public int $newOrders,
        public int $pendingOrders,
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
            'title' => 'Daily Clinic Summary',
            'body' => sprintf(
                "Appointments completed: %d\nRevenue collected: ₱%s\nNew orders: %d\nPending orders: %d",
                $this->completedAppointments,
                number_format((float) $this->revenue, 2),
                $this->newOrders,
                $this->pendingOrders,
            ),
            'icon' => 'heroicon-o-chart-bar',
            'iconColor' => 'primary',
        ];
    }
}
