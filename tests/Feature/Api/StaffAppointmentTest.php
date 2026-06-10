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

test('staff can confirm reschedule cancel and complete appointments', function (string $status, bool $expectsSms) {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => AppointmentStatus::query()->firstOrCreate(['name' => 'pending'])->id,
    ]);

    $payload = [
        'status' => $status,
    ];

    if ($status === 'rescheduled') {
        $payload['scheduled_at'] = now()->addDays(3)->toISOString();
    }

    $response = $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/appointments/{$appointment->id}/status", $payload);

    $response->assertSuccessful()
        ->assertJsonPath('data.status', $status);

    $appointment->refresh();

    expect($appointment->status->name)->toBe($status);

    if ($expectsSms) {
        expect(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
    } else {
        expect(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    }

    Http::assertNothingSent();
})->with([
    'confirmed' => ['confirmed', true],
    'rescheduled' => ['rescheduled', true],
    'cancelled' => ['cancelled', true],
    'completed' => ['completed', false],
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
