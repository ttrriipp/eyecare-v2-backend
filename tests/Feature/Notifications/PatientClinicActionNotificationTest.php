<?php

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Actions\BillingRecords\CorrectBillingPayment;
use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\CompleteEncounter;
use App\Actions\Encounters\StartEncounter;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Actions\OpticalOrders\BuildOpticalOrder;
use App\Enums\AppointmentRequestStatus;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\PatientNotificationActionType;
use App\Enums\PatientNotificationKind;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\SmsNotification;
use App\Models\User;
use App\Notifications\PatientDatabaseNotification;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-08 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function assertPatientNotification(
    User $account,
    string $title,
    PatientNotificationKind $kind,
    string $relatedType,
    int $relatedId,
    ?string $actionUrl,
    ?PatientNotificationActionType $mobileActionType,
): void {
    $notification = $account->fresh()->unreadNotifications->sole();
    $mobileAction = $mobileActionType === null ? null : [
        'type' => $mobileActionType->value,
        'id' => $relatedId,
    ];

    expect($notification->data)
        ->toHaveKey('title', $title)
        ->toHaveKey('kind', $kind->value)
        ->toHaveKey('mobile_action', $mobileAction)
        ->toHaveKey('action_url', $actionUrl)
        ->toHaveKey('related_type', $relatedType)
        ->toHaveKey('related_id', $relatedId)
        ->toHaveKey('event_key');
}

test('patient notification payload is exposed by the existing feed contract', function () {
    $account = User::factory()->patient()->create();

    app(NotifyPatientAccount::class)->handleAccount(
        $account,
        new PatientDatabaseNotification(
            kind: PatientNotificationKind::AppointmentConfirmed,
            title: 'Appointment Confirmed',
            body: 'Your appointment is confirmed.',
            icon: 'heroicon-o-calendar-days',
            status: 'success',
            mobileActionType: PatientNotificationActionType::Appointment,
            mobileActionId: 123,
            actionUrl: '/appointments/123',
            relatedType: 'appointment',
            relatedId: 123,
            eventKey: 'appointment.confirmed:123',
            patientId: $account->patient->id,
        ),
    );

    $this->actingAs($account)
        ->getJson('/api/v1/notifications')
        ->assertSuccessful()
        ->assertJsonPath('data.0.kind', 'appointment_confirmed')
        ->assertJsonPath('data.0.mobile_action.type', 'appointment')
        ->assertJsonPath('data.0.mobile_action.id', 123)
        ->assertJsonPath('data.0.type', PatientDatabaseNotification::class)
        ->assertJsonPath('data.0.action_url', '/appointments/123')
        ->assertJsonPath('data.0.related_type', 'appointment')
        ->assertJsonPath('data.0.related_id', 123);
});

test('patient delivery is skipped without a linked account and duplicate event keys stay silent', function () {
    $unlinkedPatient = Patient::factory()->create(['user_id' => null]);
    $account = User::factory()->patient()->create();
    $notification = new PatientDatabaseNotification(
        kind: PatientNotificationKind::VisitCompleted,
        title: 'Visit Completed',
        body: 'Your visit is complete.',
        icon: 'heroicon-o-check-circle',
        status: 'success',
        mobileActionType: PatientNotificationActionType::Appointment,
        mobileActionId: 123,
        actionUrl: '/appointments/123',
        relatedType: 'appointment',
        relatedId: 123,
        eventKey: 'visit.completed:123',
        patientId: $account->patient->id,
    );

    app(NotifyPatientAccount::class)->handlePatient($unlinkedPatient, $notification);
    app(NotifyPatientAccount::class)->handleAccount($account, $notification);
    app(NotifyPatientAccount::class)->handleAccount($account, $notification);

    expect($account->fresh()->notifications)->toHaveCount(1);
});

test('accepting and rejecting appointment requests notify the requesting account', function () {
    $acceptedAccount = User::factory()->patient()->create();
    $rejectedAccount = User::factory()->create();
    $reviewer = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $acceptedRequest = AppointmentRequest::factory()->create([
        'user_id' => $acceptedAccount->id,
        'patient_id' => $acceptedAccount->patient->id,
        'appointment_type_id' => $appointmentType->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-09-10 10:00:00',
    ]);
    $rejectedRequest = AppointmentRequest::factory()->create([
        'user_id' => $rejectedAccount->id,
        'patient_id' => null,
        'appointment_type_id' => $appointmentType->id,
        'status' => AppointmentRequestStatus::Pending,
        'scheduled_at' => '2026-09-11 10:00:00',
    ]);

    $appointment = app(AcceptAppointmentRequest::class)->handle(
        request: $acceptedRequest,
        reviewer: $reviewer,
        appointmentType: $appointmentType,
        durationMinutes: $appointmentType->duration_minutes,
        scheduledAt: Carbon::parse('2026-09-10 10:00:00'),
        optometrist: $optometrist,
    );
    app(RejectAppointmentRequest::class)->handle($rejectedRequest, $reviewer, 'Schedule unavailable');

    assertPatientNotification(
        $acceptedAccount,
        'Appointment Confirmed',
        PatientNotificationKind::AppointmentConfirmed,
        'appointment',
        $appointment->id,
        "/appointments/{$appointment->id}",
        PatientNotificationActionType::Appointment,
    );
    assertPatientNotification(
        $rejectedAccount,
        'Appointment Request Declined',
        PatientNotificationKind::AppointmentRequestDeclined,
        'appointment_request',
        $rejectedRequest->id,
        "/appointment-requests/{$rejectedRequest->id}",
        PatientNotificationActionType::AppointmentRequest,
    );

    expect($rejectedAccount->fresh()->notifications->sole()->data['body'])
        ->not->toContain('Schedule unavailable');
});

test('clinic appointment cancellation notifies the patient without private reason details', function () {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $account->patient->id,
        'scheduled_at' => '2026-09-10 10:00:00',
    ]);

    app(CancelAppointment::class)->handle(
        appointment: $appointment,
        initiator: 'clinic',
        actor: $staff,
        reasonCategory: 'other',
        reasonDetails: 'Private operational detail',
    );

    assertPatientNotification(
        $account,
        'Appointment Cancelled',
        PatientNotificationKind::AppointmentCancelled,
        'appointment',
        $appointment->id,
        "/appointments/{$appointment->id}",
        PatientNotificationActionType::Appointment,
    );
    expect($account->fresh()->notifications->sole()->data['body'])
        ->not->toContain('Private operational detail');

    $sms = SmsNotification::query()
        ->where('appointment_id', $appointment->id)
        ->where('event', 'appointment_cancelled')
        ->sole();

    expect($sms->recipient)->toBe($account->patient->phone)
        ->and($sms->message)->toContain($appointment->appointment_number)
        ->and($sms->message)->not->toContain('Private operational detail');
});

test('completed consultation produces one visit or prescription notification', function (bool $withPrescription, string $expectedTitle, PatientNotificationKind $expectedKind, string $expectedType, PatientNotificationActionType $expectedActionType) {
    $account = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $account->patient->id]);
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update([
        'optometrist_id' => $optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Private finding',
        'assessment' => 'Private assessment',
        'plan' => 'Private plan',
    ]);
    $encounter = app(StartEncounter::class)->handle($encounter->fresh(), $optometrist);

    $completed = app(CompleteEncounter::class)->handle(
        encounter: $encounter,
        actor: $optometrist,
        prescriptionData: $withPrescription ? [
            'main_od_sphere' => '-2.00',
            'main_os_sphere' => '-1.50',
        ] : null,
    );

    $related = $withPrescription
        ? $completed->prescriptions()->firstOrFail()
        : $appointment;
    assertPatientNotification(
        $account,
        $expectedTitle,
        $expectedKind,
        $expectedType,
        $related->id,
        $withPrescription ? "/prescriptions/{$related->id}" : "/appointments/{$related->id}",
        $expectedActionType,
    );

    expect($account->fresh()->notifications)->toHaveCount(1)
        ->and($account->fresh()->notifications->sole()->data['body'])
        ->not->toContain('Private finding')
        ->not->toContain('-2.00');
})->with([
    'without prescription' => [false, 'Visit Completed', PatientNotificationKind::VisitCompleted, 'appointment', PatientNotificationActionType::Appointment],
    'with prescription' => [true, 'Prescription Available', PatientNotificationKind::PrescriptionAvailable, 'prescription', PatientNotificationActionType::Prescription],
]);

test('prepared and immediate optical orders produce only their final creation outcome', function (string $mode, string $expectedTitle, PatientNotificationKind $expectedKind) {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();

    $order = app(BuildOpticalOrder::class)->handle(
        patientId: $account->patient->id,
        encounterId: null,
        prescriptionId: null,
        quotationId: null,
        fulfillmentMode: $mode,
        usesExternalSupplier: false,
        items: collect(),
        dispensedBy: $staff->id,
        actorId: $staff->id,
    );

    assertPatientNotification(
        $account,
        $expectedTitle,
        $expectedKind,
        'optical_order',
        $order->id,
        "/optical-orders/{$order->id}",
        PatientNotificationActionType::OpticalOrder,
    );
    expect($account->fresh()->notifications)->toHaveCount(1);

    $sms = SmsNotification::query()->where('job_order_id', $order->id)->sole();

    expect($sms->event)->toBe($mode === 'immediate' ? 'optical_order_released' : 'optical_order_confirmed')
        ->and($sms->recipient)->toBe($account->patient->phone)
        ->and($sms->message)->toContain($order->job_order_number);
})->with([
    'prepared' => ['prepared', 'Optical Order Confirmed', PatientNotificationKind::OpticalOrderConfirmed],
    'immediate' => ['immediate', 'Order Released', PatientNotificationKind::OpticalOrderReleased],
]);

test('ready and cancelled optical order transitions notify the affected patient', function (string $targetStatus, string $expectedTitle, PatientNotificationKind $expectedKind) {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => $targetStatus === JobOrderStatus::ReadyForDispensing->value
            ? JobOrderStatus::InProgress
            : JobOrderStatus::Queued,
    ]);

    app(UpdateJobOrderStatus::class)->handle($order, $targetStatus, $staff);

    assertPatientNotification(
        $account,
        $expectedTitle,
        $expectedKind,
        'optical_order',
        $order->id,
        "/optical-orders/{$order->id}",
        PatientNotificationActionType::OpticalOrder,
    );

    $sms = SmsNotification::query()->where('job_order_id', $order->id)->sole();

    expect($sms->event)->toBe($targetStatus === JobOrderStatus::ReadyForDispensing->value
        ? 'optical_order_ready'
        : 'optical_order_cancelled')
        ->and($sms->recipient)->toBe($account->patient->phone)
        ->and($sms->message)->toContain($order->job_order_number);
})->with([
    'ready' => [JobOrderStatus::ReadyForDispensing->value, 'Order Ready for Pickup', PatientNotificationKind::OpticalOrderReady],
    'cancelled' => [JobOrderStatus::Cancelled->value, 'Optical Order Cancelled', PatientNotificationKind::OpticalOrderCancelled],
]);

test('standalone optical and service payments notify with the available destination', function (bool $hasOrder, string $relatedType) {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $order = $hasOrder ? JobOrder::factory()->create(['patient_id' => $account->patient->id]) : null;
    $billing = BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order?->id,
        'total_amount' => 1000,
        'amount_paid' => 0,
        'balance_due' => 1000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $billing,
        amount: 500,
        paymentMethod: 'cash',
        recorder: $staff,
        chargesReviewed: true,
    );

    $relatedId = $order?->id ?? $billing->id;
    assertPatientNotification(
        $account,
        'Payment Recorded',
        PatientNotificationKind::PaymentRecorded,
        $relatedType,
        $relatedId,
        $order !== null ? "/optical-orders/{$order->id}" : null,
        $order !== null ? PatientNotificationActionType::OpticalOrder : null,
    );
})->with([
    'optical order payment' => [true, 'optical_order'],
    'service-only payment' => [false, 'billing_record'],
]);

test('payment correction notifies the patient without exposing internal details', function () {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $order = JobOrder::factory()->create(['patient_id' => $account->patient->id]);
    $billing = BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'total_amount' => 1000,
        'amount_paid' => 400,
        'balance_due' => 600,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);
    $payment = BillingPayment::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 400,
        'status' => 'posted',
    ]);

    app(CorrectBillingPayment::class)->handle(
        originalPayment: $payment,
        newAmount: 500,
        reason: 'Private correction reason',
        corrector: $staff,
    );

    assertPatientNotification(
        $account,
        'Payment Updated',
        PatientNotificationKind::PaymentUpdated,
        'optical_order',
        $order->id,
        "/optical-orders/{$order->id}",
        PatientNotificationActionType::OpticalOrder,
    );
    expect($account->fresh()->notifications->sole()->data['body'])
        ->not->toContain('Private correction reason');
});

test('dispensing with a pickup payment coalesces into one order released notification', function () {
    $account = User::factory()->patient()->create();
    $staff = User::factory()->staff()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-001',
        'total_amount' => 1000,
    ]);
    BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'total_amount' => 1000,
        'amount_paid' => 500,
        'balance_due' => 500,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    app(DispenseJobOrder::class)->handle(
        jobOrder: $order,
        dispenser: $staff,
        pickupPaymentAmount: 500,
        pickupPaymentMethod: 'cash',
    );

    assertPatientNotification(
        $account,
        'Order Released',
        PatientNotificationKind::OpticalOrderReleased,
        'optical_order',
        $order->id,
        "/optical-orders/{$order->id}",
        PatientNotificationActionType::OpticalOrder,
    );
    expect($account->fresh()->notifications)->toHaveCount(1);

    $sms = SmsNotification::query()->where('job_order_id', $order->id)->sole();

    expect($sms->event)->toBe('optical_order_released')
        ->and($sms->recipient)->toBe($account->patient->phone)
        ->and($sms->message)->toContain($order->job_order_number);
});
