<?php

use App\Enums\JobOrderStatus;
use App\Filament\Pages\Reports\ReorderReport;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\PatientLinkRequests\PatientLinkRequestResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\SmsNotifications\SmsNotificationResource;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\FrameReservation;
use App\Models\JobOrder;
use App\Models\NotificationStatus;
use App\Models\PatientLinkRequest;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SmsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('navigation badges count actionable sidebar work', function () {
    Appointment::factory()->create([
        'scheduled_at' => today()->setTime(10, 0),
    ]);
    Appointment::factory()->cancelled()->create([
        'scheduled_at' => today()->setTime(11, 0),
    ]);

    AppointmentRequest::factory()->create();
    AppointmentRequest::factory()->create([
        'expires_at' => now()->subMinute(),
    ]);
    AppointmentRequest::factory()->accepted()->create();

    PatientLinkRequest::factory()->pending()->create();
    PatientLinkRequest::factory()->approved()->create();

    Encounter::factory()->inProgress()->create();
    Encounter::factory()->completed()->create();

    Quotation::factory()->create();
    Quotation::factory()->accepted()->create();

    JobOrder::factory()->create(['status' => JobOrderStatus::ReadyForDispensing]);
    JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    FrameReservation::factory()->create();
    FrameReservation::factory()->accepted()->create();
    FrameReservation::factory()->accepted()->create();

    BillingRecord::factory()->create();
    BillingRecord::factory()->partiallyPaid()->create();
    BillingRecord::factory()->paid()->create();

    $failedStatus = NotificationStatus::factory()->create(['name' => 'failed']);
    $sentStatus = NotificationStatus::factory()->create(['name' => 'sent']);
    SmsNotification::factory()->create(['notification_status_id' => $failedStatus->id]);
    SmsNotification::factory()->create(['notification_status_id' => $sentStatus->id]);

    expect([
        'Appointments' => AppointmentResource::getNavigationBadge(),
        'Appointment Requests' => AppointmentRequestResource::getNavigationBadge(),
        'Link Requests' => PatientLinkRequestResource::getNavigationBadge(),
        'Encounters' => EncounterResource::getNavigationBadge(),
        'Quotations' => QuotationResource::getNavigationBadge(),
        'Optical Orders' => OpticalOrderResource::getNavigationBadge(),
        'Frame Reservations' => FrameReservationResource::getNavigationBadge(),
        'Billing & Payments' => BillingRecordResource::getNavigationBadge(),
        'SMS Log' => SmsNotificationResource::getNavigationBadge(),
    ])->toBe([
        'Appointments' => '1',
        'Appointment Requests' => '1',
        'Link Requests' => '1',
        'Encounters' => '1',
        'Quotations' => '1',
        'Optical Orders' => '1',
        'Frame Reservations' => '1',
        'Billing & Payments' => '2',
        'SMS Log' => '1',
    ]);
});

test('reorder report navigation badge counts active low-stock variants', function () {
    ProductVariant::factory()->create([
        'is_active' => true,
        'stock_quantity' => 1,
        'low_stock_threshold' => 2,
    ]);
    ProductVariant::factory()->create([
        'is_active' => false,
        'stock_quantity' => 1,
        'low_stock_threshold' => 2,
    ]);

    expect(ReorderReport::getNavigationBadge())->toBe('1');
});

test('navigation badges are omitted when there is no actionable work', function () {
    expect([
        AppointmentResource::getNavigationBadge(),
        AppointmentRequestResource::getNavigationBadge(),
        PatientLinkRequestResource::getNavigationBadge(),
        EncounterResource::getNavigationBadge(),
        QuotationResource::getNavigationBadge(),
        OpticalOrderResource::getNavigationBadge(),
        FrameReservationResource::getNavigationBadge(),
        BillingRecordResource::getNavigationBadge(),
        ReorderReport::getNavigationBadge(),
        SmsNotificationResource::getNavigationBadge(),
    ])->each->toBeNull();
});
