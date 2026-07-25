<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('staff can move appointments through the approved lifecycle', function (string $startStatus, string $targetStatus, bool $expectsSms) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => $startStatus])->id,
    ]);

    $response = $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", [
            'status' => $targetStatus,
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', $targetStatus);

    $appointment->refresh();

    expect($appointment->status->name)->toBe($targetStatus);

    if ($expectsSms) {
        expect(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
    } else {
        expect(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    }

    Http::assertNothingSent();
})->with([
    'confirmed' => ['pending', 'confirmed', true],
    'cancelled' => ['pending', 'cancelled', true],
    'arrived' => ['confirmed', 'arrived', false],
    'no-show' => ['confirmed', 'no_show', false],
    'completed' => ['arrived', 'completed', false],
]);

test('sms notification records are queued for confirm and cancel', function (string $status, string $event) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => 'pending'])->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", ['status' => $status])
        ->assertSuccessful();

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => $event,
    ]);

    expect(SmsNotification::query()->first()->status->name)->toBe('queued');

    Http::assertNothingSent();
})->with([
    'confirmed' => ['confirmed', 'appointment_confirmed'],
    'cancelled' => ['cancelled', 'appointment_cancelled'],
]);

test('arrival and completion record their workflow timestamps', function () {
    $staff = User::factory()->staff()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create(['appointment_status_id' => $confirmed->id]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", ['status' => 'arrived'])
        ->assertSuccessful();

    expect($appointment->refresh()->checked_in_at)->not->toBeNull();

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", ['status' => 'completed'])
        ->assertSuccessful();

    expect($appointment->refresh()->completed_at)->not->toBeNull();
});

test('rescheduled is not accepted as an appointment status', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", ['status' => 'rescheduled'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('customers cannot update appointment status through staff endpoint', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $customer->patient->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertForbidden();
});

test('terminal appointment statuses cannot be transitioned further', function (string $terminalStatus) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => $terminalStatus])->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
})->with([
    'cancelled' => ['cancelled'],
    'completed' => ['completed'],
    'no-show' => ['no_show'],
]);
