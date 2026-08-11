<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-11 10:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

afterEach(fn () => Carbon::setTestNow());

// ── Request Number ──────────────────────────────────────────────────────────

test('duplicate request number placeholder is absent from the form', function () {
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertDontSee('Request #');
});

// ── Appointment Type ────────────────────────────────────────────────────────

test('only one appointment type display is shown', function () {
    $type = AppointmentType::factory()->create([
        'name' => 'Internal Eye Exam',
        'patient_label' => 'Eye Checkup',
    ]);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $type->id,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Appointment Type')
        ->assertDontSee('Patient Appointment Type')
        ->assertDontSee('Internal Appointment Type');
});

test('internal appointment type name is shown when it differs from patient label', function () {
    $type = AppointmentType::factory()->create([
        'name' => 'Internal Eye Exam',
        'patient_label' => 'Eye Checkup',
    ]);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $type->id,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Eye Checkup')
        ->assertSee('Internal Eye Exam');
});

test('internal appointment type name is not shown when it matches patient label', function () {
    $type = AppointmentType::factory()->create([
        'name' => 'Eye Checkup',
        'patient_label' => 'Eye Checkup',
    ]);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $type->id,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Eye Checkup');
});

// ── Referral Source ─────────────────────────────────────────────────────────

test('referral source section is hidden when empty', function () {
    $request = AppointmentRequest::factory()->linked()->create([
        'encrypted_referring_source' => null,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertDontSee('Referral');
});

test('referral source section is visible when populated', function () {
    $request = AppointmentRequest::factory()->linked()->create([
        'encrypted_referring_source' => 'Dr. Smith',
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Referral')
        ->assertSee('Dr. Smith');
});

// ── Expires / Overdue / Latest Requested Time ───────────────────────────────

test('expires and overdue are no longer displayed', function () {
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertDontSee('Expires')
        ->assertDontSee('Overdue');
});

test('latest requested time is displayed', function () {
    $expiresAt = now()->addHours(48);
    $request = AppointmentRequest::factory()->linked()->create([
        'expires_at' => $expiresAt,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Latest Requested Time')
        ->assertSee($expiresAt->format('M j, Y g:i A'));
});

// ── Submitted Time ──────────────────────────────────────────────────────────

test('submitted time and relative request age are combined', function () {
    $createdAt = now()->subHours(3);
    $request = AppointmentRequest::factory()->linked()->create([
        'created_at' => $createdAt,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Submitted')
        ->assertSee($createdAt->format('M j, Y \a\t g:i A'))
        ->assertSee('3 hours ago');
});

// ── Unlinked Identity Fields ────────────────────────────────────────────────

test('essential unlinked identity fields remain visible', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create([
        'patient_id' => null,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Patient Information')
        ->assertSee('Name')
        ->assertSee('Date of Birth')
        ->assertSee('Phone')
        ->assertSee('Email');
});

test('gender occupation and address are visible in patient information', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create([
        'patient_id' => null,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Patient Information')
        ->assertSee('Gender')
        ->assertSee('Occupation')
        ->assertSee('Home Address');
});

// ── Potential Matches ───────────────────────────────────────────────────────

test('potential matches remain available for unlinked requests', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create([
        'patient_id' => null,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Potential Matches');
});

// ── Decision Details ────────────────────────────────────────────────────────

test('decision details appear only for resolved requests', function () {
    $pending = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
    ]);
    $accepted = AppointmentRequest::factory()->linked()->accepted()->create();

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $pending->getRouteKey()])
        ->assertDontSee('Decision Details');

    Livewire::test(ViewAppointmentRequest::class, ['record' => $accepted->getRouteKey()])
        ->assertSee('Decision Details')
        ->assertSee('Resolved By')
        ->assertSee('Resolved At');
});

test('rejection reason appears only for rejected requests', function () {
    $rejected = AppointmentRequest::factory()->linked()->rejected()->create([
        'rejection_reason' => 'Patient no longer needs this slot.',
    ]);
    $accepted = AppointmentRequest::factory()->linked()->accepted()->create();

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $rejected->getRouteKey()])
        ->assertSee('Rejection Reason')
        ->assertSee('Patient no longer needs this slot.');

    Livewire::test(ViewAppointmentRequest::class, ['record' => $accepted->getRouteKey()])
        ->assertDontSee('Rejection Reason');
});

// ── Actions ─────────────────────────────────────────────────────────────────

test('link accept and reject actions remain available under same conditions', function () {
    $unlinked = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null]);
    $linked = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $unlinked->getRouteKey()])
        ->assertActionVisible('linkToPatient')
        ->assertActionHidden('accept')
        ->assertActionVisible('reject');

    Livewire::test(ViewAppointmentRequest::class, ['record' => $linked->getRouteKey()])
        ->assertActionHidden('linkToPatient')
        ->assertActionVisible('accept')
        ->assertActionVisible('reject');
});

// ── Requested Schedule ──────────────────────────────────────────────────────

test('requested schedule section shows primary and alternative time badges', function () {
    $type = AppointmentType::factory()->create();
    $request = AppointmentRequest::factory()
        ->linked()
        ->withAlternatives()
        ->create(['appointment_type_id' => $type->id]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Requested Schedule')
        ->assertSee('Primary')
        ->assertSee('Alt 1')
        ->assertSee('Alt 2');
});

// ── Visit Reason ────────────────────────────────────────────────────────────

test('visit reason is shown in request summary', function () {
    $request = AppointmentRequest::factory()->linked()->create([
        'encrypted_reason_for_visit' => 'Annual eye checkup',
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Request Summary')
        ->assertSee('Reason for Visit')
        ->assertSee('Annual eye checkup');
});

// ── Patient Information ─────────────────────────────────────────────────────

test('linked request shows linked patient identity', function () {
    $patient = Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $request = AppointmentRequest::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Patient Information')
        ->assertSee('Maria Santos');
});

test('unlinked request does not show linked patient section', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null]);

    $this->actingAs($this->staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertDontSee('Linked Patient');
});
