<?php

use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('appointment request has an auto-generated request number', function () {
    $request = AppointmentRequest::factory()->create();

    expect($request->request_number)->toMatch('/^APR-\d{4}-\d{6}$/');
});

test('appointment request belongs to a user', function () {
    $user = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create(['user_id' => $user->id]);

    expect($request->user->id)->toBe($user->id);
});

test('linked request has a patient_id', function () {
    $request = AppointmentRequest::factory()->linked()->create();

    expect($request->patient_id)->not->toBeNull()
        ->and($request->needsPatientResolution())->toBeFalse()
        ->and($request->isReadyForScheduleReview())->toBeTrue();
});

test('unlinked request has null patient_id', function () {
    $request = AppointmentRequest::factory()->create(['patient_id' => null]);

    expect($request->patient_id)->toBeNull()
        ->and($request->needsPatientResolution())->toBeTrue()
        ->and($request->isReadyForScheduleReview())->toBeFalse();
});

test('reason for visit is encrypted', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_reason_for_visit' => 'Blurred vision in left eye',
    ]);

    $raw = DB::table('appointment_requests')->where('id', $request->id)->first();

    expect($raw->encrypted_reason_for_visit)->not->toBe('Blurred vision in left eye');
});

test('appointment_id is unique when set', function () {
    $appointment = Appointment::factory()->create();

    AppointmentRequest::factory()->create(['appointment_id' => $appointment->id]);

    expect(fn () => AppointmentRequest::factory()->create([
        'appointment_id' => $appointment->id,
    ]))->toThrow(QueryException::class);
});

test('factory states produce valid records', function () {
    $pending = AppointmentRequest::factory()->create();
    $accepted = AppointmentRequest::factory()->accepted()->create();
    $rejected = AppointmentRequest::factory()->rejected()->create();
    $cancelled = AppointmentRequest::factory()->cancelled()->create();
    $expired = AppointmentRequest::factory()->expired()->create();

    expect($pending->status)->toBe(AppointmentRequestStatus::Pending)
        ->and($accepted->status)->toBe(AppointmentRequestStatus::Accepted)
        ->and($rejected->status)->toBe(AppointmentRequestStatus::Rejected)
        ->and($cancelled->status)->toBe(AppointmentRequestStatus::Cancelled)
        ->and($expired->status)->toBe(AppointmentRequestStatus::Expired);
});

test('pending request is detected correctly', function () {
    $pending = AppointmentRequest::factory()->create([
        'expires_at' => now()->addHours(24),
    ]);
    $expired = AppointmentRequest::factory()->expired()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($expired->isPending())->toBeFalse();
});
