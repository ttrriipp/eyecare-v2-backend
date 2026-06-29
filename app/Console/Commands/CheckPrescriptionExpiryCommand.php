<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\User;
use App\Notifications\PrescriptionsExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckPrescriptionExpiryCommand extends Command
{
    protected $signature = 'prescriptions:check-expiry';

    protected $description = 'Notify staff about prescriptions expiring within 30 days';

    public function handle(): int
    {
        $expiring = Prescription::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->where(function ($query) {
                $query->whereNull('last_expiry_notified_at')
                    ->orWhere('last_expiry_notified_at', '<', now()->subDays(30));
            })
            ->with('customer:id,name')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No expiring prescriptions to notify about.');

            return self::SUCCESS;
        }

        $staffUsers = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
            ->get();

        $lines = $expiring->map(fn (Prescription $p) => sprintf(
            '• %s — expires %s',
            $p->customer?->name ?? 'Unknown',
            $p->expires_at->format('M j, Y'),
        ))->implode("\n");

        Notification::send($staffUsers, new PrescriptionsExpiringNotification(
            count: $expiring->count(),
            summary: $lines,
        ));

        $expiring->each->update(['last_expiry_notified_at' => now()]);

        $this->info("Notified staff about {$expiring->count()} expiring prescription(s).");

        return self::SUCCESS;
    }
}
