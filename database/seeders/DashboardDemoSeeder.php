<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitReason;
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
 * (statuses, visit reasons, payment methods, a staff user) come from the base
 * seeders, so run `db:seed` first if the database is empty.
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
        $visitReasonIds = VisitReason::query()->pluck('id')->all();

        if ($statusIds->isEmpty() || $visitReasonIds === []) {
            return;
        }

        $customerIds = $this->customerPool(12);
        $staffId = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'staff'))
            ->value('id');

        // 30 days of history through a week of upcoming bookings.
        for ($offset = -30; $offset <= 7; $offset++) {
            $date = today()->addDays($offset);
            $count = $date->isWeekend() ? fake()->numberBetween(0, 3) : fake()->numberBetween(3, 8);

            for ($i = 0; $i < $count; $i++) {
                $statusName = $this->statusForDate($date);

                Appointment::query()->create([
                    'customer_id' => fake()->randomElement($customerIds),
                    'staff_id' => $staffId,
                    'visit_reason_id' => fake()->randomElement($visitReasonIds),
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
            return fake()->randomElement(['confirmed', 'confirmed', 'pending']);
        }

        if ($date->isFuture()) {
            return fake()->randomElement(['pending', 'confirmed', 'confirmed']);
        }

        return fake()->randomElement(['completed', 'completed', 'completed', 'confirmed', 'cancelled']);
    }

    private function seedRevenue(): void
    {
        if (Payment::query()->where('reference_number', 'like', self::PAYMENT_REFERENCE_PREFIX.'%')->exists()) {
            return;
        }

        $paidStatusId = BillingStatus::query()->where('name', 'paid')->value('id');
        $postedStatusId = PaymentStatus::query()->where('name', 'posted')->value('id');
        $paymentMethodId = PaymentMethod::query()->value('id');

        if (! $paidStatusId || ! $postedStatusId || ! $paymentMethodId) {
            return;
        }

        $customerIds = $this->customerPool(12);
        $sequence = 1;

        // Two months of sales so "this month vs last month" reads meaningfully.
        for ($offset = -60; $offset <= 0; $offset++) {
            $date = today()->addDays($offset);
            $sales = $date->isWeekend() ? fake()->numberBetween(0, 2) : fake()->numberBetween(1, 4);

            for ($i = 0; $i < $sales; $i++) {
                $amount = (float) fake()->randomElement([600, 850, 1200, 1500, 1800, 2500, 3200, 4500]);
                $paidAt = $date->copy()->setTime(fake()->numberBetween(9, 17), fake()->randomElement([0, 30]));

                $billing = Billing::query()->create([
                    'customer_id' => fake()->randomElement($customerIds),
                    'billing_status_id' => $paidStatusId,
                    'subtotal' => $amount,
                    'total_amount' => $amount,
                    'amount_paid' => $amount,
                    'balance_due' => 0,
                    'issued_at' => $paidAt,
                ]);

                Payment::query()->create([
                    'billing_id' => $billing->id,
                    'payment_status_id' => $postedStatusId,
                    'payment_method_id' => $paymentMethodId,
                    'amount' => $amount,
                    'reference_number' => self::PAYMENT_REFERENCE_PREFIX.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                    'paid_at' => $paidAt,
                ]);

                $sequence++;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function customerPool(int $minimum): array
    {
        $customerRoleId = Role::query()->where('name', 'customer')->value('id');

        $existing = User::query()
            ->when($customerRoleId, fn ($query) => $query->where('role_id', $customerRoleId))
            ->pluck('id')
            ->all();

        if (count($existing) >= $minimum) {
            return $existing;
        }

        $created = User::factory()
            ->count($minimum - count($existing))
            ->customer()
            ->create()
            ->pluck('id')
            ->all();

        return array_merge($existing, $created);
    }
}
