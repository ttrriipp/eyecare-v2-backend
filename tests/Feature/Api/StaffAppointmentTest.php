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

test('staff can confirm reschedule cancel and complete appointments', function (string $startStatus, string $targetStatus, bool $expectsSms) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => $startStatus])->id,
    ]);

    $payload = [
        'status' => $targetStatus,
    ];

    if ($targetStatus === 'rescheduled') {
        $payload['scheduled_at'] = now()->addDays(3)->toISOString();
    }

    $response = $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", $payload);

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
    'rescheduled' => ['pending', 'rescheduled', true],
    'cancelled' => ['pending', 'cancelled', true],
    'completed' => ['confirmed', 'completed', false],
]);

test('sms notification records are queued for confirm reschedule and cancel', function (string $status, string $event) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => 'pending'])->id,
    ]);

    $payload = ['status' => $status];

    if ($status === 'rescheduled') {
        $payload['scheduled_at'] = now()->addDays(2)->toISOString();
    }

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", $payload)
        ->assertSuccessful();

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => $event,
    ]);

    expect(SmsNotification::query()->first()->status->name)->toBe('queued');

    Http::assertNothingSent();
})->with([
    'confirmed' => ['confirmed', 'appointment_confirmed'],
    'rescheduled' => ['rescheduled', 'appointment_rescheduled'],
    'cancelled' => ['cancelled', 'appointment_cancelled'],
]);

test('customers cannot update appointment status through staff endpoint', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
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
]);
