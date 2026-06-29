<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\DailySummaryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendDailySummaryCommand extends Command
{
    protected $signature = 'clinic:daily-summary';

    protected $description = 'Send a daily operations summary notification to admin users';

    public function handle(): int
    {
        $completedAppointments = Appointment::query()
            ->whereHas('status', fn ($q) => $q->where('name', 'completed'))
            ->whereDate('updated_at', today())
            ->count();

        $revenue = Payment::query()
            ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
            ->whereDate('created_at', today())
            ->sum('amount');

        $newOrders = Order::query()->whereDate('created_at', today())->count();

        $pendingOrders = Order::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['requested', 'confirmed', 'processing']))
            ->count();

        $admins = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
            ->get();

        if ($admins->isEmpty()) {
            $this->info('No admin users to notify.');

            return self::SUCCESS;
        }

        Notification::send($admins, new DailySummaryNotification(
            completedAppointments: $completedAppointments,
            revenue: $revenue,
            newOrders: $newOrders,
            pendingOrders: $pendingOrders,
        ));

        $this->info('Daily summary sent to admin users.');

        return self::SUCCESS;
    }
}
