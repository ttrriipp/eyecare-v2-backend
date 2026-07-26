<?php

use App\Models\Appointment;
use App\Models\ClinicHour;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeder creates two optometrists', function () {
    $this->seed(DatabaseSeeder::class);

    $optometrists = User::query()->optometrists()->get();
    expect($optometrists)->toHaveCount(2);
});

test('seeder creates staff with appropriate capabilities', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@eyecare.test')->first();
    $staff = User::query()->where('email', 'staff@eyecare.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->is_optometrist)->toBeTrue()
        ->and($staff)->not->toBeNull()
        ->and($staff->is_optometrist)->toBeTrue();
});

test('seeder creates linked and unlinked patients', function () {
    $this->seed(DatabaseSeeder::class);

    $linkedPatients = Patient::query()->whereNotNull('user_id')->count();
    $unlinkedPatients = Patient::query()->whereNull('user_id')->count();

    expect($linkedPatients)->toBeGreaterThanOrEqual(1)
        ->and($unlinkedPatients)->toBeGreaterThanOrEqual(1);
});

test('seeder creates appointments in multiple statuses', function () {
    $this->seed(DatabaseSeeder::class);

    $statuses = Appointment::query()
        ->pluck('appointment_status_id')
        ->unique();

    expect($statuses->count())->toBeGreaterThanOrEqual(2);
});

test('seeder creates encounters and prescriptions', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Encounter::count())->toBeGreaterThanOrEqual(1)
        ->and(Prescription::count())->toBeGreaterThanOrEqual(1);
});

test('seeder creates quotations and job orders', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Quotation::count())->toBeGreaterThanOrEqual(1)
        ->and(JobOrder::count())->toBeGreaterThanOrEqual(1);
});

test('seeder creates invoices', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Invoice::count())->toBeGreaterThanOrEqual(1);
});

test('seeder creates clinic hours for all seven days', function () {
    $this->seed(DatabaseSeeder::class);

    $clinicHours = ClinicHour::count();
    expect($clinicHours)->toBe(7);
});

test('migrate fresh seed succeeds without errors', function () {
    // This test verifies the seeder runs without exceptions
    $this->seed(DatabaseSeeder::class);

    // If we get here, the seeder succeeded
    expect(true)->toBeTrue();
});
