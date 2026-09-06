<?php

use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\FrameRatings\Pages\EditFrameRating;
use App\Filament\Resources\OpticalOrders\Pages\EditOpticalOrder;
use App\Filament\Resources\PatientAccounts\Pages\ViewPatientAccount;
use App\Filament\Resources\PatientLinkRequests\Pages\ViewPatientLinkRequest;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\VisitRatings\Pages\ViewVisitRating;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VisitRating;

test('unlinked appointment request titles use the request label and submitted patient identity', function () {
    $this->actingAs(User::factory()->make());
    $record = new AppointmentRequest;
    $record->encrypted_identity_snapshot = ['first_name' => 'Liza', 'last_name' => 'Mendoza'];
    $record->setRelation('patient', null);
    $page = new ViewAppointmentRequest;
    $page->record = $record;

    expect($page->getTitle())->toBe('Appointment Request for Liza Mendoza');
});

test('patient detail pages use the patient name in their title', function (string $pageClass, string $modelClass, string $expected) {
    $this->actingAs(User::factory()->make());
    $patient = Patient::factory()->make(['first_name' => 'Liza', 'middle_name' => null, 'last_name' => 'Mendoza']);
    $record = $modelClass === Patient::class ? $patient : new $modelClass;
    $record->setRelation('patient', $patient);
    $record->setRelation('reviewedPatient', $patient);
    $page = new $pageClass;
    $page->record = $record;

    expect($page->getTitle())->toBe($expected);
})->with([
    'ViewAppointmentRequest' => [ViewAppointmentRequest::class, AppointmentRequest::class, 'Appointment Request for Liza Mendoza'],
    'EditAppointment' => [EditAppointment::class, Appointment::class, 'Appointment for Liza Mendoza'],
    'EditOpticalOrder' => [EditOpticalOrder::class, JobOrder::class, 'Optical Order for Liza Mendoza'],
    'EditQuotation' => [EditQuotation::class, Quotation::class, 'Quotation for Liza Mendoza'],
    'EditBillingRecord' => [EditBillingRecord::class, BillingRecord::class, 'Billing for Liza Mendoza'],
    'ViewPrescription' => [ViewPrescription::class, Prescription::class, 'Prescription for Liza Mendoza'],
    'ViewVisitRating' => [ViewVisitRating::class, VisitRating::class, 'View for Liza Mendoza'],
    'EditFrameRating' => [EditFrameRating::class, FrameRating::class, 'Edit for Liza Mendoza'],
    'EditPatient' => [EditPatient::class, Patient::class, 'Edit Records for Liza Mendoza'],
    'ViewPatientAccount' => [ViewPatientAccount::class, User::class, 'View Account for Liza Mendoza'],
    'ViewPatientLinkRequest' => [ViewPatientLinkRequest::class, PatientLinkRequest::class, 'View Link Request for Liza Mendoza'],
    'EditEncounter' => [EditEncounter::class, Encounter::class, 'Consultation for Liza Mendoza'],
]);
