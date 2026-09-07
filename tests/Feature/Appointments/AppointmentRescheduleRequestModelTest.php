<?php

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\AppointmentStatus;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-07 08:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('request state and relationships are cast consistently', function (): void {
    $request = AppointmentRescheduleRequest::factory()->create([
        'status' => AppointmentRescheduleRequestStatus::Pending,
        'alternative_scheduled_times' => [
            '2026-09-12T11:00:00+08:00',
        ],
    ]);

    expect($request->status)->toBe(AppointmentRescheduleRequestStatus::Pending)
        ->and($request->alternative_scheduled_times)->toBe([
            '2026-09-12T11:00:00+08:00',
        ])
        ->and($request->current_scheduled_at)->toBeInstanceOf(Carbon::class)
        ->and($request->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($request->appointment)->toBeInstanceOf(Appointment::class)
        ->and($request->patient)->toBeInstanceOf(Patient::class)
        ->and($request->user)->toBeInstanceOf(User::class)
        ->and($request->request_number)->toMatch('/^ARR-2026-\d{6}$/');
});

test('pending request becomes effectively expired after its expiry time', function (): void {
    $request = AppointmentRescheduleRequest::factory()->create([
        'status' => AppointmentRescheduleRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    expect($request->effectiveStatus())->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($request->isPending())->toBeFalse();
});

test('terminal request statuses remain terminal regardless of expiry', function (): void {
    $request = AppointmentRescheduleRequest::factory()->create([
        'status' => AppointmentRescheduleRequestStatus::Rejected,
        'expires_at' => now()->subMinute(),
    ]);

    expect($request->effectiveStatus())->toBe(AppointmentRescheduleRequestStatus::Rejected)
        ->and($request->isPending())->toBeFalse();
});

test('pending request becomes expired when its appointment is no longer scheduled', function (): void {
    $request = AppointmentRescheduleRequest::factory()->create([
        'status' => AppointmentRescheduleRequestStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);

    $request->appointment->update([
        'appointment_status_id' => AppointmentStatus::factory()
            ->create(['name' => AppointmentStatusName::Cancelled->value])
            ->id,
    ]);
    $request->refresh();

    expect($request->effectiveStatus())->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($request->isPending())->toBeFalse();
});
