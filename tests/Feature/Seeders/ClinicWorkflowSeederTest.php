<?php

use App\Enums\EncounterStatus;
use App\Models\Appointment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\ClinicHour;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SavedFrame;
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

test('seeder creates idempotent saved frame preferences for the linked demo account', function () {
    $this->seed(DatabaseSeeder::class);

    $patientUser = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();
    $savedFrameCount = SavedFrame::query()->where('user_id', $patientUser->id)->count();

    expect($savedFrameCount)->toBe(3);

    $this->seed(DatabaseSeeder::class);

    expect(SavedFrame::query()->where('user_id', $patientUser->id)->count())->toBe($savedFrameCount);
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

test('seeder creates a complete consultation with linked clinical records', function () {
    $this->seed(DatabaseSeeder::class);

    $encounter = Encounter::query()
        ->where('encounter_number', 'CON-2026-000001')
        ->firstOrFail();
    $appointment = $encounter->appointment;
    $prescription = $encounter->prescriptions()->firstOrFail();
    $quotation = Quotation::query()->where('encounter_id', $encounter->id)->firstOrFail();
    $jobOrder = $quotation->jobOrder;
    $billingRecord = BillingRecord::query()->where('encounter_id', $encounter->id)->firstOrFail();

    expect($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->patient_id)->toBe($appointment?->patient_id)
        ->and($encounter->optometrist_id)->not->toBeNull()
        ->and($encounter->completed_by)->toBe($encounter->optometrist_id)
        ->and($encounter->started_at)->not->toBeNull()
        ->and($encounter->completed_at)->not->toBeNull()
        ->and($encounter->last_wizard_step)->toBe(4)
        ->and($encounter->draft_saved_at)->not->toBeNull()
        ->and($appointment)->not->toBeNull()
        ->and($appointment->status?->name)->toBe('fulfilled')
        ->and($appointment->optometrist_id)->toBe($encounter->optometrist_id)
        ->and($appointment->checked_in_at)->not->toBeNull()
        ->and($appointment->checked_in_by)->toBe($appointment->created_by)
        ->and($appointment->fulfilled_at)->not->toBeNull()
        ->and($appointment->reason_for_visit)->not->toBeEmpty()
        ->and($appointment->staff_notes)->not->toBeEmpty()
        ->and($prescription->patient_id)->toBe($encounter->patient_id)
        ->and($prescription->appointment_id)->toBe($appointment->id)
        ->and($prescription->created_by)->toBe($encounter->optometrist_id)
        ->and($prescription->prescribed_at)->not->toBeNull()
        ->and($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->prescription_id)->toBe($prescription->id)
        ->and($jobOrder?->patient_id)->toBe($encounter->patient_id)
        ->and($jobOrder?->prescription_id)->toBe($prescription->id)
        ->and($billingRecord->patient_id)->toBe($encounter->patient_id)
        ->and($billingRecord->job_order_id)->toBe($jobOrder?->id);

    foreach ([
        'chief_complaint',
        'past_ocular_history',
        'past_surgical_history',
        'past_medical_history',
        'allergies',
        'medications',
        'findings',
        'supporting_test_results',
        'assessment',
        'plan',
        'remarks',
    ] as $field) {
        expect($encounter->{$field})->toBeString()->not->toBeEmpty();
    }

    foreach ([
        'main_od_value',
        'main_od_sphere',
        'main_od_cylinder',
        'main_os_value',
        'main_os_sphere',
        'main_os_cylinder',
        'add_od_value',
        'add_od_sphere',
        'add_od_cylinder',
        'add_os_value',
        'add_os_sphere',
        'add_os_cylinder',
        'remarks',
    ] as $field) {
        expect($prescription->{$field})->toBeString()->not->toBeEmpty();
    }
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

test('seeded billing records include itemized charges', function () {
    $this->seed(DatabaseSeeder::class);

    $billingRecords = BillingRecord::query()->with('items')->get();

    expect($billingRecords)->not->toBeEmpty();

    $billingRecords->each(function (BillingRecord $billingRecord): void {
        expect($billingRecord->items)->not->toBeEmpty()
            ->and($billingRecord->items->sum(fn (BillingRecordItem $item): float => (float) $item->amount))
            ->toBe((float) $billingRecord->subtotal_amount);
    });

    $itemCount = BillingRecordItem::count();
    $this->seed(DatabaseSeeder::class);

    expect(BillingRecordItem::count())->toBe($itemCount);
});

test('seeded quotations and orders include itemized products', function () {
    $this->seed(DatabaseSeeder::class);

    $quotations = Quotation::query()->with('items')->get();
    $orders = JobOrder::query()->with('items')->get();

    expect($quotations)->not->toBeEmpty()
        ->and($orders)->not->toBeEmpty();

    $quotations->each(function (Quotation $quotation): void {
        expect($quotation->items)->not->toBeEmpty()
            ->and($quotation->items->sum(fn (QuotationItem $item): float => (float) $item->amount))
            ->toBe((float) $quotation->subtotal);
    });

    $orders->each(function (JobOrder $order): void {
        expect($order->items)->not->toBeEmpty()
            ->and($order->items->sum(fn (JobOrderItem $item): float => (float) $item->amount))
            ->toBe((float) $order->total_amount);
    });
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
