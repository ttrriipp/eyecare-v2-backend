<?php

use App\Models\Appointment;
use App\Models\BillingRecord;
use App\Models\ClinicHour;
use App\Models\Encounter;
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
        ->and($admin->is_optometrist)->toBeFalse()
        ->and($staff)->not->toBeNull()
        ->and($staff->is_optometrist)->toBeFalse();
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

    $quotation = Quotation::query()->firstOrFail();
    $jobOrder = JobOrder::query()->firstOrFail();

    expect(Quotation::count())->toBeGreaterThanOrEqual(1)
        ->and(JobOrder::count())->toBeGreaterThanOrEqual(1);
});

test('seeder creates billing-records', function () {
    $this->seed(DatabaseSeeder::class);

    expect(BillingRecord::count())->toBeGreaterThanOrEqual(1);
});

test('seeded quotations, job orders, and billing records have internally consistent data', function () {
    $this->seed(DatabaseSeeder::class);

    // Reference numbers follow the same YEAR-sequence convention across
    // quotations, job orders, and billing records — a seeder previously
    // hardcoded quotation numbers without the year segment.
    Quotation::query()->pluck('quotation_number')->each(
        fn (string $number) => expect($number)->toMatch('/^QUO-\d{4}-\d{6}$/'),
    );

    // subtotal_amount must never be left at its zero default when there's
    // no discount — it should equal total_amount in that case.
    BillingRecord::query()->where('discount_amount', 0)->get()->each(
        fn (BillingRecord $record) => expect((float) $record->subtotal_amount)
            ->toBe((float) $record->total_amount),
    );

    // A job order in a terminal status must record when it got there.
    JobOrder::query()->where('status', 'dispensed')->get()->each(
        fn (JobOrder $jobOrder) => expect($jobOrder->dispensed_at)->not->toBeNull(),
    );
    JobOrder::query()->where('status', 'cancelled')->get()->each(
        fn (JobOrder $jobOrder) => expect($jobOrder->cancelled_at)->not->toBeNull(),
    );
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
