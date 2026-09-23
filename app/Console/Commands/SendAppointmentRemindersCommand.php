<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsJob;
use App\Models\Appointment;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use App\Services\SmsMessageFormatter;
use Illuminate\Console\Command;

class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'appointments:send-reminders
                            {--test-appointment= : Appointment ID for a one-off five-minute reminder test}
                            {--test-recipient= : E.164 phone number controlled by the operator}';

    protected $description = 'Queue 24-hour appointment reminders or one explicitly targeted five-minute test reminder';

    public function handle(): int
    {
        $queuedStatusId = NotificationStatus::query()->where('name', 'queued')->value('id');

        if (! $queuedStatusId) {
            $this->error('Notification status "queued" not found.');

            return self::FAILURE;
        }

        if ($this->option('test-appointment') !== null || $this->option('test-recipient') !== null) {
            return $this->queueTestReminder((int) $queuedStatusId);
        }

        $reminderWindowStart = now()->addDay()->startOfMinute()->subMinute();
        $reminderWindowEnd = $reminderWindowStart->copy()->addMinutes(2);

        $appointments = Appointment::query()
            ->with('patient')
            ->whereHas('status', fn ($query) => $query->where('name', 'scheduled'))
            ->where('scheduled_at', '>=', $reminderWindowStart)
            ->where('scheduled_at', '<', $reminderWindowEnd)
            ->get();

        $created = 0;

        foreach ($appointments as $appointment) {
            $phone = $appointment->patient?->phone;

            if (! $phone) {
                continue;
            }

            $sms = $this->createReminder($appointment, $phone, $queuedStatusId);

            if ($sms->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("Created {$created} appointment reminder(s) approximately 24 hours before their appointments.");

        return self::SUCCESS;
    }

    private function queueTestReminder(int $queuedStatusId): int
    {
        $appointmentId = filter_var($this->option('test-appointment'), FILTER_VALIDATE_INT);
        $recipient = $this->option('test-recipient');

        if ($appointmentId === false || $appointmentId === null || $appointmentId < 1 || ! is_string($recipient) || preg_match('/^\\+[1-9]\\d{7,14}$/', $recipient) !== 1) {
            $this->error('A valid --test-appointment ID and an E.164 --test-recipient number are both required.');

            return self::FAILURE;
        }

        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->whereHas('status', fn ($query) => $query->where('name', 'scheduled'))
            ->where('scheduled_at', '>=', now()->addMinutes(4))
            ->where('scheduled_at', '<=', now()->addMinutes(6))
            ->first();

        if ($appointment === null) {
            $this->error('The selected appointment must be scheduled and about five minutes away.');

            return self::FAILURE;
        }

        $sms = $this->createReminder($appointment, $recipient, $queuedStatusId);

        if (! $sms->wasRecentlyCreated) {
            $this->info('A matching reminder already exists; no duplicate test SMS was queued.');

            return self::SUCCESS;
        }

        SendSmsJob::dispatch($sms);

        $this->info('Queued one five-minute test reminder to the supplied number.');

        return self::SUCCESS;
    }

    private function createReminder(Appointment $appointment, string $recipient, int $queuedStatusId): SmsNotification
    {
        $message = SmsMessageFormatter::brand("Reminder: Your appointment is on {$appointment->scheduled_at->format('M j')} at {$appointment->scheduled_at->format('g:i A')}. See you at Padilla Optical Clinic!");

        return SmsNotification::query()->firstOrCreate([
            'appointment_id' => $appointment->id,
            'event' => 'appointment_reminder',
            'recipient' => $recipient,
            'message' => $message,
        ], [
            'notification_status_id' => $queuedStatusId,
        ]);
    }
}
