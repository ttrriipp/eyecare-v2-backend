<?php

namespace App\Console\Commands;

use App\Actions\Appointments\LockAppointmentScheduleDate;
use App\Actions\Appointments\ScheduleAppointment;
use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

#[Signature('appointments:create-sms-test {--force : Confirm that temporary test records should be created} {--minutes-ahead=7 : Minutes until the test appointment, from 7 to 120}')]
#[Description('Create a temporary appointment for a targeted SMS reminder test')]
class CreateSmsTestAppointmentCommand extends Command
{
    public function handle(
        DatabaseManager $database,
        LockAppointmentScheduleDate $lockAppointmentScheduleDate,
        ScheduleAppointment $scheduleAppointment,
    ): int {
        if (! $this->option('force')) {
            $this->error('This command creates temporary patient and appointment records. Re-run with --force to confirm.');

            return self::FAILURE;
        }

        $minutesAhead = filter_var($this->option('minutes-ahead'), FILTER_VALIDATE_INT);

        if ($minutesAhead === false || $minutesAhead < 7 || $minutesAhead > 120) {
            $this->error('The test appointment must be scheduled 7 to 120 minutes ahead.');

            return self::FAILURE;
        }

        $appointmentType = AppointmentType::query()
            ->active()
            ->orderBy('duration_minutes')
            ->orderBy('id')
            ->first();
        $scheduledStatusId = AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Scheduled->value)
            ->value('id');

        if ($appointmentType === null || $scheduledStatusId === null) {
            $this->error('An active appointment type and the scheduled appointment status are required.');

            return self::FAILURE;
        }

        $scheduledAt = now()->addMinutes($minutesAhead);

        try {
            $appointment = $database->transaction(function () use (
                $appointmentType,
                $lockAppointmentScheduleDate,
                $scheduleAppointment,
                $scheduledAt,
                $scheduledStatusId,
            ): Appointment {
                $lockAppointmentScheduleDate->handle($scheduledAt);
                $scheduleAppointment->handle(
                    scheduledAt: $scheduledAt,
                    durationMinutes: (int) $appointmentType->duration_minutes,
                    enforceGrid: false,
                );

                $patient = Patient::query()->create([
                    'first_name' => 'SMS Reminder Test',
                    'last_name' => now()->format('YmdHis'),
                ]);

                return Appointment::query()->create([
                    'patient_id' => $patient->id,
                    'appointment_type_id' => $appointmentType->id,
                    'duration_minutes' => $appointmentType->duration_minutes,
                    'source' => 'sms_test',
                    'appointment_status_id' => $scheduledStatusId,
                    'scheduled_at' => $scheduledAt,
                    'reason_for_visit' => 'Temporary SMS reminder test',
                    'staff_notes' => 'Temporary SMS reminder test appointment. Remove after testing.',
                ]);
            }, attempts: 3);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->error(is_string($message) ? $message : 'The selected time cannot be scheduled.');

            return self::FAILURE;
        }

        $this->info("Created temporary SMS test appointment ID {$appointment->id} ({$appointment->appointment_number}) for {$appointment->scheduled_at->format('M j, Y g:i:s A')}.");
        $this->line("Fake patient: {$appointment->patient->full_name} ({$appointment->patient->patient_number}); no phone number or booking SMS was added.");
        $minutesUntilReminderWindow = max(0, $minutesAhead - 6);
        $this->line("The five-minute reminder window starts in about {$minutesUntilReminderWindow} minute(s). For a manual test, run: php artisan appointments:send-reminders --test-appointment={$appointment->id} --test-recipient=+63XXXXXXXXXX (replace with your test number) when the appointment is 4–6 minutes away.");

        return self::SUCCESS;
    }
}
