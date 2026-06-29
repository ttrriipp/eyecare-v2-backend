<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Illuminate\Console\Command;

class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Send SMS reminders for tomorrow\'s confirmed appointments';

    public function handle(): int
    {
        $queuedStatusId = NotificationStatus::query()->where('name', 'queued')->value('id');

        if (! $queuedStatusId) {
            $this->error('Notification status "queued" not found.');

            return self::FAILURE;
        }

        $tomorrow = today()->addDay();

        $appointments = Appointment::query()
            ->with('customer')
            ->whereHas('status', fn ($query) => $query->where('name', 'confirmed'))
            ->whereDate('scheduled_at', $tomorrow)
            ->get();

        // Batch idempotency check: skip appointments that already have a reminder today
        $alreadyReminded = SmsNotification::query()
            ->whereIn('appointment_id', $appointments->pluck('id'))
            ->where('event', 'appointment_reminder')
            ->whereDate('created_at', today())
            ->pluck('appointment_id');

        $created = 0;

        foreach ($appointments as $appointment) {
            $phone = $appointment->customer?->phone;

            if (! $phone) {
                continue;
            }

            if ($alreadyReminded->contains($appointment->id)) {
                continue;
            }

            SmsNotification::query()->create([
                'appointment_id' => $appointment->id,
                'notification_status_id' => $queuedStatusId,
                'event' => 'appointment_reminder',
                'recipient' => $phone,
                'message' => "Reminder: You have an appointment tomorrow at {$appointment->scheduled_at->format('g:i A')}. See you at Padilla Optical Clinic!",
            ]);

            $created++;
        }

        $this->info("Created {$created} appointment reminder(s) for {$tomorrow->toDateString()}.");

        return self::SUCCESS;
    }
}
