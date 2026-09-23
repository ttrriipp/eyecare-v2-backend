<?php

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Models\VisitRating;
use Carbon\Carbon;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('canonical seed data creates required users', function () {
    $this->seed(DatabaseSeeder::class);

    $owner = User::query()->where('email', 'owner@eyecare.test')->first();
    $admin = User::query()->where('email', 'admin@eyecare.test')->first();
    $staff = User::query()->where('email', 'staff@eyecare.test')->first();
    $patientUser = User::query()->where('email', 'customer@eyecare.test')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->is_optometrist)->toBeTrue()
        ->and($owner->role->name)->toBe('admin')
        ->and($admin)->not->toBeNull()
        ->and($admin->is_optometrist)->toBeFalse()
        ->and($admin->role->name)->toBe('admin')
        ->and($staff)->not->toBeNull()
        ->and($staff->is_optometrist)->toBeFalse()
        ->and($staff->role->name)->toBe('staff')
        ->and($patientUser)->not->toBeNull()
        ->and($patientUser->role->name)->toBe('patient');
});

test('canonical seeded users use structured names without the legacy name column', function () {
    $this->seed(DatabaseSeeder::class);

    $owner = User::query()->where('email', 'owner@eyecare.test')->firstOrFail();
    $admin = User::query()->where('email', 'admin@eyecare.test')->firstOrFail();
    $optometrist = User::query()->where('email', 'optometrist@eyecare.test')->firstOrFail();
    $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();
    $patientUser = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    expect(Schema::hasColumn('users', 'name'))->toBeFalse()
        ->and($owner->first_name)->toBe('Maria')
        ->and($owner->last_name)->toBe('Santos')
        ->and($admin->first_name)->toBe('Carlos')
        ->and($admin->last_name)->toBe('Reyes')
        ->and($optometrist->first_name)->toBe('Juan')
        ->and($optometrist->last_name)->toBe('dela Cruz')
        ->and($staff->first_name)->toBe('Ana')
        ->and($staff->last_name)->toBe('Garcia')
        ->and($patientUser->first_name)->toBe('Liza')
        ->and($patientUser->last_name)->toBe('Mendoza');
});

test('canonical seed data creates linked and walk-in patients', function () {
    $this->seed(DatabaseSeeder::class);

    $linkedPatient = Patient::query()->where('first_name', 'Liza')->where('last_name', 'Mendoza')->first();
    $walkInPatient = Patient::query()->where('first_name', 'Pedro')->where('last_name', 'Cruz')->first();

    expect($linkedPatient)->not->toBeNull()
        ->and($linkedPatient->user_id)->not->toBeNull()
        ->and($walkInPatient)->not->toBeNull()
        ->and($walkInPatient->user_id)->toBeNull();
});

test('canonical seed data fills the planned consultation and patient profile', function () {
    $this->seed(DatabaseSeeder::class);

    $encounter = Encounter::query()
        ->with(['patient', 'appointment.appointmentType', 'appointment.status', 'optometrist'])
        ->where('encounter_number', 'CON-2026-000002')
        ->firstOrFail();

    expect($encounter->patient?->full_name)->toBe('Pedro Cruz')
        ->and($encounter->patient?->occupation)->toBe('Software Engineer')
        ->and($encounter->patient?->address)->toBe('Quezon City, Metro Manila')
        ->and($encounter->patient?->contact_email)->toBe('pedro.cruz@eyecare.test')
        ->and($encounter->appointment?->appointment_number)->toBe('APT-2026-000007')
        ->and($encounter->appointment?->appointmentType?->name)->toBe('Routine Check-up')
        ->and($encounter->appointment?->status?->name)->toBe('checked_in')
        ->and($encounter->appointment?->reason_for_visit)->toBe('Eye strain and intermittent blurred vision after long screen use.')
        ->and($encounter->chief_complaint)->toBe('Eye strain and intermittent blurred vision after long screen use.')
        ->and($encounter->optometrist?->isOptometrist())->toBeTrue();
});

test('rerunning canonical seed data repairs the planned consultation details', function () {
    $this->seed(DatabaseSeeder::class);

    $encounter = Encounter::query()
        ->where('encounter_number', 'CON-2026-000002')
        ->firstOrFail();
    $encounter->patient()->update([
        'occupation' => null,
        'address' => null,
        'contact_email' => null,
    ]);
    $encounter->appointment()->update(['reason_for_visit' => null]);
    $encounter->update([
        'appointment_id' => null,
        'chief_complaint' => null,
    ]);

    $this->seed(DatabaseSeeder::class);

    $repairedEncounter = Encounter::query()
        ->with(['patient', 'appointment'])
        ->where('encounter_number', 'CON-2026-000002')
        ->firstOrFail();

    expect($repairedEncounter->patient?->occupation)->toBe('Software Engineer')
        ->and($repairedEncounter->patient?->address)->toBe('Quezon City, Metro Manila')
        ->and($repairedEncounter->patient?->contact_email)->toBe('pedro.cruz@eyecare.test')
        ->and($repairedEncounter->appointment?->appointment_number)->toBe('APT-2026-000007')
        ->and($repairedEncounter->appointment?->reason_for_visit)->toBe('Eye strain and intermittent blurred vision after long screen use.')
        ->and($repairedEncounter->chief_complaint)->toBe('Eye strain and intermittent blurred vision after long screen use.');
});

test('canonical seed data links the in-progress consultation to its appointment', function () {
    $this->seed(DatabaseSeeder::class);

    $encounter = Encounter::query()
        ->with('appointment.status')
        ->where('encounter_number', 'CON-2026-000003')
        ->firstOrFail();

    expect($encounter->appointment?->appointment_number)->toBe('APT-2026-000009')
        ->and($encounter->appointment?->status?->name)->toBe('checked_in');
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

test('canonical seed data omits the checked-in scenario appointment', function () {
    $this->seed(DatabaseSeeder::class);

    $noShowAppointment = Appointment::query()
        ->where('appointment_number', 'APT-2026-000005')
        ->firstOrFail();

    expect(Appointment::query()->where('appointment_number', 'APT-2026-000003')->exists())->toBeFalse()
        ->and($noShowAppointment->status?->name)->toBe('no_show')
        ->and(Appointment::generateAppointmentNumber())->toBe('APT-2026-000010');
});

test('canonical seed data omits the cancelled billing scenario record', function () {
    $this->seed(DatabaseSeeder::class);

    expect(BillingRecord::query()
        ->where('status', BillingRecordStatus::Cancelled)
        ->exists())->toBeFalse();
});

test('canonical seed data creates a complete cancelled referral appointment', function () {
    $this->seed(DatabaseSeeder::class);

    $appointment = Appointment::query()
        ->where('appointment_number', 'APT-2026-000004')
        ->firstOrFail();
    $duplicateAppointment = Appointment::query()
        ->where('appointment_number', 'APT-2026-000008')
        ->firstOrFail();
    $cancelledEncounter = Encounter::query()
        ->where('encounter_number', 'CON-2026-000004')
        ->firstOrFail();
    $duplicateEncounter = Encounter::query()
        ->where('encounter_number', 'CON-2026-000005')
        ->firstOrFail();
    $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();

    expect($appointment->patient?->full_name)->toBe('Pedro Cruz')
        ->and($appointment->appointmentType?->name)->toBe('Referral')
        ->and($appointment->duration_minutes)->toBe(45)
        ->and($appointment->status?->name)->toBe('cancelled')
        ->and($appointment->referring_source)->toBe('Dr. Garcia - City Hospital')
        ->and($appointment->reason_for_visit)->toBe('Referral for persistent blurred vision and eye strain.')
        ->and($appointment->contact_notes)->toBe('Patient could not attend the original appointment slot.')
        ->and($appointment->cancelled_by)->toBe('clinic')
        ->and($appointment->cancelled_by_user_id)->toBe($staff->id)
        ->and($appointment->cancellation_reason_category)->toBe('schedule_conflict')
        ->and($appointment->cancellation_reason_details)->toBe('The clinic could not keep the original appointment slot.')
        ->and($appointment->cancelled_at)->not->toBeNull()
        ->and($duplicateAppointment->patient_id)->toBe($appointment->patient_id)
        ->and($duplicateAppointment->status?->name)->toBe('cancelled')
        ->and($duplicateAppointment->cancelled_at)->not->toBeNull()
        ->and($cancelledEncounter->patient_id)->toBe($appointment->patient_id)
        ->and($cancelledEncounter->appointment_id)->toBe($appointment->id)
        ->and($cancelledEncounter->completed_at)->toBeNull()
        ->and($duplicateEncounter->status)->toBe(EncounterStatus::Cancelled)
        ->and($duplicateEncounter->appointment_id)->toBe($duplicateAppointment->id)
        ->and($duplicateEncounter->completed_at)->toBeNull();
});

test('canonical seed data creates a complete secondary consultation', function () {
    $this->seed(DatabaseSeeder::class);

    $encounter = Encounter::query()
        ->with(['patient', 'appointment.appointmentType', 'appointment.status', 'optometrist'])
        ->where('encounter_number', 'CON-2026-000006')
        ->firstOrFail();
    $prescription = $encounter->prescriptions()
        ->where('prescription_number', 'RX-2026-000002')
        ->firstOrFail();
    $amendment = $encounter->prescriptions()
        ->where('prescription_number', 'RX-2026-000003')
        ->firstOrFail();

    expect($encounter->patient?->full_name)->toBe('Pedro Cruz')
        ->and($encounter->appointment?->appointment_number)->toBe('APT-2026-000006')
        ->and($encounter->appointment?->appointmentType?->name)->toBe('Routine Check-up')
        ->and($encounter->appointment?->status?->name)->toBe('fulfilled')
        ->and($encounter->optometrist?->isOptometrist())->toBeTrue()
        ->and($encounter->status)->toBe(EncounterStatus::Completed)
        ->and($encounter->completed_by)->toBe($encounter->optometrist_id)
        ->and($encounter->last_wizard_step)->toBe(3)
        ->and($encounter->draft_saved_at)->not->toBeNull()
        ->and($prescription->prescription_number)->toBe('RX-2026-000002')
        ->and($prescription->appointment_id)->toBe($encounter->appointment_id)
        ->and($prescription->main_od_value)->toBe('1.00')
        ->and($prescription->main_os_value)->toBe('1.00')
        ->and($prescription->isCurrentVersion())->toBeFalse()
        ->and($amendment->previous_prescription_id)->toBe($prescription->id)
        ->and($amendment->isCurrentVersion())->toBeTrue()
        ->and($amendment->amendment_reason)->toBe('Corrected refraction values after verification of the original measurements.')
        ->and($amendment->main_od_sphere)->toBe('-1.50')
        ->and($amendment->main_os_sphere)->toBe('-1.75');

    foreach ([
        'chief_complaint',
        'past_ocular_history',
        'past_surgical_history',
        'past_medical_history',
        'allergies',
        'medications',
        'findings',
        'remarks',
    ] as $field) {
        expect($encounter->{$field})->toBeString()->not->toBeEmpty();
    }
});

test('rerunning canonical seed data repairs missing prescription expiration dates', function () {
    $this->seed(DatabaseSeeder::class);

    Prescription::query()
        ->whereIn('prescription_number', ['RX-2026-000001', 'RX-2026-000002'])
        ->update(['expires_at' => null]);

    $this->seed(DatabaseSeeder::class);

    $prescriptions = Prescription::query()
        ->whereIn('prescription_number', ['RX-2026-000001', 'RX-2026-000002'])
        ->get();

    expect($prescriptions)->toHaveCount(2);

    $prescriptions->each(function (Prescription $prescription): void {
        expect($prescription->expires_at)->not->toBeNull()
            ->and($prescription->expires_at?->toDateString())->toBe(
                $prescription->prescribed_at?->copy()->addMonthsNoOverflow(6)->toDateString(),
            );
    });
});

test('canonical seed data creates deterministic appointment request scenarios with complete decisions', function () {
    $this->seed(DatabaseSeeder::class);

    $requests = AppointmentRequest::query()
        ->with('resolvedBy')
        ->get()
        ->keyBy('request_number');

    $expectedReasons = [
        'APR-2026-000001' => 'Routine eye exam and prescription update.',
        'APR-2026-000002' => 'Blurred vision and eye strain while working on a computer.',
        'APR-2026-000003' => 'Eye pain and redness needing urgent assessment.',
        'APR-2026-000004' => 'Patient requested cancellation due to a schedule conflict.',
        'APR-2026-000005' => 'Follow-up after a recent prescription change.',
        'APR-2026-000006' => 'New prescription for headaches after prolonged screen use.',
        'APR-2026-000007' => 'Routine eye examination and updated distance prescription.',
        'APR-2026-000008' => 'Eye strain assessment before starting a new contact lens prescription.',
    ];

    expect($requests)->toHaveCount(count($expectedReasons));

    foreach ($expectedReasons as $requestNumber => $reason) {
        $request = $requests->get($requestNumber);

        expect($request)->not->toBeNull()
            ->and($request?->encrypted_reason_for_visit)->toBe($reason);
    }

    $accepted = $requests->get('APR-2026-000002');
    $pending = $requests->get('APR-2026-000001');
    $rejected = $requests->get('APR-2026-000003');
    $expired = $requests->get('APR-2026-000005');
    $firstConflict = $requests->get('APR-2026-000006');
    $secondConflict = $requests->get('APR-2026-000007');
    $independentPending = $requests->get('APR-2026-000008');
    $additionalPending = collect([
        $firstConflict,
        $secondConflict,
        $independentPending,
    ]);
    $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();
    $pendingPatient = Patient::query()->where('patient_number', 'PAT-2026-000003')->firstOrFail();

    expect($pending?->status)->toBe(AppointmentRequestStatus::Pending)
        ->and($pending?->patient_id)->toBe($pendingPatient->id)
        ->and($pending?->user_id)->toBe($pendingPatient->user_id)
        ->and($pending?->expires_at?->isFuture())->toBeTrue()
        ->and($accepted?->status)->toBe(AppointmentRequestStatus::Accepted)
        ->and($accepted?->resolvedBy?->id)->toBe($staff->id)
        ->and($accepted?->resolved_at)->not->toBeNull()
        ->and($accepted?->appointment_id)->not->toBeNull()
        ->and($rejected?->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($rejected?->resolvedBy?->id)->toBe($staff->id)
        ->and($rejected?->resolved_at)->not->toBeNull()
        ->and($rejected?->scheduled_at?->format('H:i'))->toBe('18:00')
        ->and($rejected?->rejection_reason)->toBe('Requested time is outside clinic hours for this appointment type.')
        ->and($expired?->status)->toBe(AppointmentRequestStatus::Expired)
        ->and($expired?->scheduled_at?->isPast())->toBeTrue()
        ->and($expired?->scheduled_at?->format('H:i'))->toBe('10:00')
        ->and($expired?->expires_at?->isPast())->toBeTrue()
        ->and($additionalPending)->toHaveCount(3)
        ->and($additionalPending->pluck('patient_id')->unique())->toHaveCount(3)
        ->and($additionalPending->pluck('user_id')->unique())->toHaveCount(3)
        ->and($additionalPending->every(
            fn (AppointmentRequest $request): bool => $request->status === AppointmentRequestStatus::Pending
                && $request->alternative_scheduled_times !== null
                && count($request->alternative_scheduled_times) === 2,
        ))->toBeTrue()
        ->and($firstConflict?->scheduled_at?->equalTo($secondConflict?->scheduled_at))->toBeTrue()
        ->and($independentPending?->scheduled_at?->equalTo($firstConflict?->scheduled_at))->toBeFalse()
        ->and($firstConflict?->expires_at?->equalTo(
            Carbon::parse($firstConflict?->alternative_scheduled_times[1]),
        ))->toBeTrue()
        ->and($secondConflict?->expires_at?->equalTo(
            Carbon::parse($secondConflict?->alternative_scheduled_times[1]),
        ))->toBeTrue();
});

test('rerunning the canonical seeder repairs edited appointment request demo data', function () {
    $this->seed(DatabaseSeeder::class);

    $pending = AppointmentRequest::query()->where('request_number', 'APR-2026-000001')->firstOrFail();
    $pending->update(['encrypted_reason_for_visit' => 'Random demo text.']);

    $this->seed(DatabaseSeeder::class);

    $reviewOrder = JobOrder::query()
        ->with(['items', 'dispensingEvents', 'billingRecord.items', 'billingRecord.payments'])
        ->where('job_order_number', 'ORD-2026-000004')
        ->firstOrFail();

    expect($pending->fresh()->encrypted_reason_for_visit)->toBe('Routine eye exam and prescription update.')
        ->and($reviewOrder->items)->toHaveCount(4)
        ->and($reviewOrder->dispensingEvents)->toHaveCount(1)
        ->and($reviewOrder->billingRecord->items)->toHaveCount(4)
        ->and($reviewOrder->billingRecord->payments->where('status', 'posted'))->toHaveCount(1)
        ->and(FrameRating::query()->publiclyDisplayable()->count())->toBe(8);
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

    $processingOrder = JobOrder::query()
        ->with('billingRecord')
        ->where('job_order_number', 'ORD-2026-000003')
        ->firstOrFail();

    expect(Encounter::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(Prescription::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(JobOrder::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(BillingRecord::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(BillingPayment::query()->count())->toBeGreaterThanOrEqual(1)
        ->and($processingOrder->billingRecord)->not->toBeNull()
        ->and($processingOrder->billingRecord?->status)->toBe(BillingRecordStatus::Unpaid)
        ->and((float) $processingOrder->billingRecord?->total_amount)->toBe(4200.0);
});

test('canonical seed data includes public product reviews and visit feedback for completed encounters', function () {
    $this->seed(DatabaseSeeder::class);

    $patient = Patient::query()->where('patient_number', 'PAT-2026-000001')->firstOrFail();
    $request = AccessoryOrderRequest::query()
        ->with('items.productVariant.product')
        ->where('request_number', 'ORQ-2026-000001')
        ->firstOrFail();
    $feedback = VisitRating::query()
        ->whereHas('appointment', fn ($query) => $query->where('appointment_number', 'APT-2026-000002'))
        ->firstOrFail();
    $walkInFeedback = VisitRating::query()
        ->whereHas('appointment', fn ($query) => $query->where('appointment_number', 'APT-2026-000006'))
        ->firstOrFail();
    $productRating = FrameRating::query()
        ->where('patient_id', $patient->id)
        ->whereHas('variant', fn ($query) => $query->where('sku', 'FRM-SPORT-BLKRED-001'))
        ->firstOrFail();
    $additionalProductRating = FrameRating::query()
        ->whereHas('variant', fn ($query) => $query->where('sku', 'ACC-LACRYL-HYDRATE-10ML'))
        ->firstOrFail();

    expect($request->user_id)->toBe($patient->user_id)
        ->and($request->patient_id)->toBe($patient->id)
        ->and($request->status)->toBe(AccessoryOrderRequestStatus::Pending)
        ->and($request->items)->toHaveCount(2)
        ->and($request->items->pluck('item_kind')->unique()->all())->toBe([CommercialItemKind::Accessory])
        ->and((float) $request->subtotal_amount)->toBe(1300.0)
        ->and($feedback->patient_id)->toBe($patient->id)
        ->and($feedback->rating)->toBe(5)
        ->and($feedback->comment)->toBe('Friendly and thorough consultation. The prescription explanation was clear.')
        ->and($walkInFeedback->rating)->toBe(4)
        ->and($walkInFeedback->comment)->toBe('Clear advice and a patient explanation. The updated prescription feels right for driving.')
        ->and($productRating->rating)->toBe(5)
        ->and($productRating->comment)->toBe('Secure fit and a sporty shape that works well outdoors.')
        ->and($productRating->public_display_consent_at)->not->toBeNull()
        ->and($additionalProductRating->public_display_consent_at)->not->toBeNull()
        ->and(FrameRating::query()->publiclyDisplayable()->count())->toBe(8);

    $seededProductRatings = FrameRating::query()
        ->with(['dispensingEvent.jobOrder.items', 'dispensingEvent.billingRecord'])
        ->publiclyDisplayable()
        ->get();

    foreach ($seededProductRatings as $seededProductRating) {
        $dispensingEvent = $seededProductRating->dispensingEvent;
        $dispensedOrder = $dispensingEvent?->jobOrder;

        expect($dispensingEvent)->not->toBeNull()
            ->and($dispensedOrder?->status)->toBe(JobOrderStatus::Dispensed)
            ->and($dispensedOrder?->patient_id)->toBe($seededProductRating->patient_id)
            ->and($dispensedOrder?->items->contains('product_variant_id', $seededProductRating->product_variant_id))->toBeTrue()
            ->and($dispensingEvent?->billingRecord?->status)->toBe(BillingRecordStatus::Paid);
    }

    $visitFeedbackRows = VisitRating::query()
        ->with(['appointment.status', 'encounter'])
        ->get();

    expect($visitFeedbackRows)->toHaveCount(2);

    foreach ($visitFeedbackRows as $visitFeedbackRow) {
        expect($visitFeedbackRow->appointment->patient_id)->toBe($visitFeedbackRow->patient_id)
            ->and($visitFeedbackRow->appointment->status->name)->toBe(AppointmentStatusName::Fulfilled->value)
            ->and($visitFeedbackRow->encounter->appointment_id)->toBe($visitFeedbackRow->appointment_id)
            ->and($visitFeedbackRow->encounter->patient_id)->toBe($visitFeedbackRow->patient_id)
            ->and($visitFeedbackRow->encounter->status)->toBe(EncounterStatus::Completed);
    }
});

test('canonical seed data includes multiple linked accessory order request scenarios', function () {
    $this->seed(DatabaseSeeder::class);

    $requests = AccessoryOrderRequest::query()
        ->with(['patient', 'items'])
        ->whereIn('request_number', [
            'ORQ-2026-000001',
            'ORQ-2026-000002',
            'ORQ-2026-000003',
            'ORQ-2026-000004',
        ])
        ->get()
        ->keyBy('request_number');

    expect($requests)->toHaveCount(4)
        ->and($requests['ORQ-2026-000001']->status)->toBe(AccessoryOrderRequestStatus::Pending)
        ->and($requests['ORQ-2026-000002']->status)->toBe(AccessoryOrderRequestStatus::Pending)
        ->and($requests['ORQ-2026-000002']->patient->patient_number)->toBe('PAT-2026-000004')
        ->and($requests['ORQ-2026-000003']->status)->toBe(AccessoryOrderRequestStatus::Rejected)
        ->and($requests['ORQ-2026-000003']->patient->patient_number)->toBe('PAT-2026-000005')
        ->and($requests['ORQ-2026-000003']->rejection_reason)->not->toBeEmpty()
        ->and($requests['ORQ-2026-000003']->resolved_by)->not->toBeNull()
        ->and($requests['ORQ-2026-000004']->status)->toBe(AccessoryOrderRequestStatus::Cancelled)
        ->and($requests['ORQ-2026-000004']->patient->patient_number)->toBe('PAT-2026-000006')
        ->and($requests['ORQ-2026-000004']->encrypted_cancellation_reason)->not->toBeEmpty()
        ->and($requests['ORQ-2026-000004']->cancelled_at)->not->toBeNull();

    foreach ($requests as $request) {
        expect($request->user_id)->toBe($request->patient->user_id)
            ->and($request->items)->not->toBeEmpty()
            ->and($request->items->pluck('item_kind')->unique()->all())->toBe([CommercialItemKind::Accessory]);
    }
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
