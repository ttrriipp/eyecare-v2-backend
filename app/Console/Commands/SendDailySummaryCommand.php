<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\BillingPayment;
use App\Models\JobOrder;
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
            ->whereHas('status', fn ($q) => $q->where('name', 'fulfilled'))
            ->whereDate('updated_at', today())
            ->count();

        $revenue = BillingPayment::query()
            ->where('status', 'posted')
            ->whereDate('recorded_at', today())
            ->sum('amount');

        $newJobOrders = JobOrder::query()->whereDate('created_at', today())->count();

        $pendingJobOrders = JobOrder::query()
            ->whereIn('status', ['queued', 'in_progress'])
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
            newJobOrders: $newJobOrders,
            pendingJobOrders: $pendingJobOrders,
        ));

        $this->info('Daily summary sent to admin users.');

        return self::SUCCESS;
    }
}
