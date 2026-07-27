<?php

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('canonical seed data creates linked and walk-in patients', function () {
    $this->seed(DatabaseSeeder::class);

    $linkedPatient = Patient::query()->where('full_name', 'Ana Reyes')->first();
    $walkInPatient = Patient::query()->where('full_name', 'Pedro Cruz')->first();

    expect($linkedPatient)->not->toBeNull()
        ->and($linkedPatient->user_id)->not->toBeNull()
        ->and($walkInPatient)->not->toBeNull()
        ->and($walkInPatient->user_id)->toBeNull();
});

test('canonical seed data creates appointment types with durations', function () {
    $this->seed(DatabaseSeeder::class);

    $types = AppointmentType::query()->pluck('duration_minutes', 'name');

    expect($types)->toHaveKeys(['New Patient', 'Follow-up', 'Routine Check-up', 'Referral'])
        ->and($types['New Patient'])->toBe(30)
        ->and($types['Follow-up'])->toBe(15)
        ->and($types['Routine Check-up'])->toBe(30)
        ->and($types['Referral'])->toBe(30);
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

test('canonical seed data creates complete clinic workflow', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Encounter::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(Prescription::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(JobOrder::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(Invoice::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(InvoicePayment::query()->count())->toBeGreaterThanOrEqual(1);
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
