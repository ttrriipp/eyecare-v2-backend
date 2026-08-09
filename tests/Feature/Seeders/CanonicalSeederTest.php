<?php

use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('canonical seed data creates required users', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@eyecare.test')->first();
    $staff = User::query()->where('email', 'staff@eyecare.test')->first();
    $patientUser = User::query()->where('email', 'customer@eyecare.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->is_optometrist)->toBeTrue()
        ->and($admin->role->name)->toBe('admin')
        ->and($staff)->not->toBeNull()
        ->and($staff->is_optometrist)->toBeTrue()
        ->and($staff->role->name)->toBe('staff')
        ->and($patientUser)->not->toBeNull()
        ->and($patientUser->role->name)->toBe('patient');
});

test('canonical seeded users use structured names without the legacy name column', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@eyecare.test')->firstOrFail();
    $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();
    $patientUser = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    expect(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and($admin->first_name)->toBe('Maria')
        ->and($admin->last_name)->toBe('Santos')
        ->and($staff->first_name)->toBe('Juan')
        ->and($staff->last_name)->toBe('dela Cruz')
        ->and($patientUser->first_name)->toBe('Ana')
        ->and($patientUser->last_name)->toBe('Reyes');
});

test('canonical seed data creates linked and walk-in patients', function () {
    $this->seed(DatabaseSeeder::class);

    $linkedPatient = Patient::query()->where('first_name', 'Ana')->where('last_name', 'Reyes')->first();
    $walkInPatient = Patient::query()->where('first_name', 'Pedro')->where('last_name', 'Cruz')->first();

    expect($linkedPatient)->not->toBeNull()
        ->and($linkedPatient->user_id)->not->toBeNull()
        ->and($walkInPatient)->not->toBeNull()
        ->and($walkInPatient->user_id)->toBeNull();
});

test('canonical seed data creates appointment types with durations', function () {
    $this->seed(DatabaseSeeder::class);

    $types = AppointmentType::query()->pluck('duration_minutes', 'name');

    expect($types)->toHaveKeys(['New Patient', 'Follow-up', 'Routine Check-up', 'Referral', 'Problem/Urgent Visit', 'Contact Lens Consultation'])
        ->and($types['New Patient'])->toBe(45)
        ->and($types['Follow-up'])->toBe(15)
        ->and($types['Routine Check-up'])->toBe(30)
        ->and($types['Referral'])->toBe(45)
        ->and($types['Problem/Urgent Visit'])->toBe(30)
        ->and($types['Contact Lens Consultation'])->toBe(45);
});

test('canonical seed data creates appointments with duration snapshots', function () {
    $this->seed(DatabaseSeeder::class);

    $appointments = Appointment::query()->whereHas('appointmentType')->get();

    expect($appointments)->not->toBeEmpty();

    $appointments->each(function (Appointment $appointment): void {
        expect($appointment->duration_minutes)->not->toBeNull()
            ->and($appointment->duration_minutes)->toBe($appointment->appointmentType->duration_minutes);
    });
});

test('appointment statuses are seeded canonically without pruning the transition bridge', function () {
    AppointmentStatus::query()->create(['name' => 'transition_bridge']);

    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);

    $statusNames = AppointmentStatus::query()->pluck('name');

    expect($statusNames->all())
        ->toContain(...array_map(
            fn (AppointmentStatusName $status): string => $status->value,
            AppointmentStatusName::cases(),
        ))
        ->and(
            AppointmentStatus::query()
                ->whereIn('name', array_column(AppointmentStatusName::cases(), 'value'))
                ->count(),
        )->toBe(count(AppointmentStatusName::cases()));
});

test('canonical seed data creates complete clinic workflow', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Encounter::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(Prescription::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(JobOrder::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(BillingRecord::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(BillingPayment::query()->count())->toBeGreaterThanOrEqual(1);
});

test('seed data has no legacy model references', function () {
    $this->seed(DatabaseSeeder::class);

    expect(class_exists('App\\Models\\VisitReason'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\VisitReasonFactory'))->toBeFalse()
        ->and(class_exists('Database\\Seeders\\VisitReasonSeeder'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\OrderFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\OrderItemFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\OrderStatusFactory'))->toBeFalse()
        ->and(class_exists('Database\\Seeders\\OrderStatusSeeder'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\BillingFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\BillingItemFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\BillingStatusFactory'))->toBeFalse()
        ->and(class_exists('Database\\Seeders\\BillingStatusSeeder'))->toBeFalse()
        ->and(Schema::hasTable('visit_reasons'))->toBeFalse()
        ->and(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('billings'))->toBeFalse()
        ->and(Schema::hasTable('payments'))->toBeFalse();
});
