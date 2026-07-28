<?php

namespace Database\Seeders;

use App\Enums\BillingRecordStatus;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Volume demo data for the dashboard so the appointments trend chart and the
 * stat sparklines look alive during the defense.
 *
 * This is intentionally NOT wired into DatabaseSeeder (it would bloat tests and
 * the narrative ClinicWorkflowSeeder). Run it explicitly before a demo:
 *
 *   vendor/bin/sail artisan db:seed --class=DashboardDemoSeeder
 *
 * Idempotent: re-running skips records it has already created. Prerequisites
 * (statuses, appointment types, a staff user) come from the base seeders, so
 * run `db:seed` first if the database is empty.
 */
class DashboardDemoSeeder extends Seeder
{
    private const APPOINTMENT_MARKER = '[dashboard-demo]';

    private const PAYMENT_REFERENCE_PREFIX = 'DASH-';

    public function run(): void
    {
        $this->seedAppointments();
        $this->seedRevenue();
    }

    private function seedAppointments(): void
    {
        if (Appointment::query()->where('staff_notes', self::APPOINTMENT_MARKER)->exists()) {
            return;
        }

        $statusIds = AppointmentStatus::query()->pluck('id', 'name');
        $appointmentTypeIds = AppointmentType::query()->pluck('id')->all();

        if ($statusIds->isEmpty() || $appointmentTypeIds === []) {
            return;
        }

        $patientIds = $this->patientPool(12);
        $staffId = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'staff'))
            ->value('id');

        for ($offset = -30; $offset <= 7; $offset++) {
            $date = today()->addDays($offset);
            $count = $date->isWeekend() ? fake()->numberBetween(0, 3) : fake()->numberBetween(3, 8);

            for ($i = 0; $i < $count; $i++) {
                $statusName = $this->statusForDate($date);

                Appointment::query()->create([
                    'appointment_number' => Appointment::generateAppointmentNumber(),
                    'patient_id' => fake()->randomElement($patientIds),
                    'created_by' => $staffId,
                    'appointment_type_id' => fake()->randomElement($appointmentTypeIds),
                    'appointment_status_id' => $statusIds[$statusName] ?? $statusIds->first(),
                    'scheduled_at' => $date->copy()->setTime(
                        fake()->numberBetween(9, 16),
                        fake()->randomElement([0, 15, 30, 45]),
                    ),
                    'staff_notes' => self::APPOINTMENT_MARKER,
                ]);
            }
        }
    }

    private function statusForDate(Carbon $date): string
    {
        if ($date->isToday()) {
            return fake()->randomElement(['scheduled', 'scheduled', 'checked_in']);
        }

        if ($date->isFuture()) {
            return fake()->randomElement(['scheduled', 'scheduled', 'scheduled']);
        }

        return fake()->randomElement(['fulfilled', 'fulfilled', 'fulfilled', 'scheduled', 'cancelled']);
    }

    private function seedRevenue(): void
    {
        if (BillingPayment::query()->where('reference_number', 'like', self::PAYMENT_REFERENCE_PREFIX.'%')->exists()) {
            return;
        }

        $patientIds = $this->patientPool(12);
        $staffId = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'staff'))
            ->value('id');

        if (! $staffId) {
            return;
        }

        $sequence = 1;

        for ($offset = -60; $offset <= 0; $offset++) {
            $date = today()->addDays($offset);
            $sales = $date->isWeekend() ? fake()->numberBetween(0, 2) : fake()->numberBetween(1, 4);

            for ($i = 0; $i < $sales; $i++) {
                $amount = (float) fake()->randomElement([600, 850, 1200, 1500, 1800, 2500, 3200, 4500]);
                $paidAt = $date->copy()->setTime(fake()->numberBetween(9, 17), fake()->randomElement([0, 30]));

                $billingRecord = BillingRecord::query()->create([
                    'billing_record_number' => 'BR-DASH-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                    'job_order_id' => \App\Models\JobOrder::factory()->create([
                        'patient_id' => fake()->randomElement($patientIds),
                        'status' => \App\Enums\JobOrderStatus::Dispensed,
                    ])->id,
                    'patient_id' => fake()->randomElement($patientIds),
                    'status' => BillingRecordStatus::Paid,
                    'total_amount' => $amount,
                    'amount_paid' => $amount,
                    'balance_due' => 0,
                    'recorded_by' => $staffId,
                    'recorded_at' => $paidAt,
                ]);

                BillingPayment::query()->create([
                    'billing_record_id' => $billingRecord->id,
                    'status' => 'posted',
                    'payment_method' => fake()->randomElement(['cash', 'gcash', 'bank_transfer']),
                    'amount' => $amount,
                    'reference_number' => self::PAYMENT_REFERENCE_PREFIX.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                    'recorded_by' => $staffId,
                    'recorded_at' => $paidAt,
                ]);

                $sequence++;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function patientPool(int $minimum): array
    {
        $existing = Patient::query()->pluck('id')->all();

        if (count($existing) >= $minimum) {
            return $existing;
        }

        $created = Patient::factory()
            ->count($minimum - count($existing))
            ->create()
            ->pluck('id')
            ->all();

        return array_merge($existing, $created);
    }
}
