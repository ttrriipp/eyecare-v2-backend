<?php

use App\Enums\AppointmentRequestKind;
use App\Enums\AppointmentRequestStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function rebookingRequestData(Appointment $appointment, array $overrides = []): array
{
    return array_merge([
        'appointment_id' => $appointment->id,
        'scheduled_at' => '2026-07-14T10:00:00+08:00',
        'reason_for_visit' => null,
    ], $overrides);
}

function scheduledAppointmentFor(User $user, array $overrides = []): Appointment
{
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();

    return Appointment::factory()->create(array_merge([
        'patient_id' => $user->patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => $type->duration_minutes,
        'scheduled_at' => '2026-07-13 10:00:00',
    ], $overrides));
}

test('a patient can submit a rebooking request linked to an existing appointment', function (): void {
    $user = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($user);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment));

    $response->assertCreated()
        ->assertJsonPath('data.request_type', AppointmentRequestKind::Reschedule->value)
        ->assertJsonPath('data.appointment.id', $appointment->id)
        ->assertJsonPath('data.appointment.scheduled_at', '2026-07-13T02:00:00.000000Z')
        ->assertJsonPath('data.original_scheduled_at', '2026-07-13T02:00:00.000000Z')
        ->assertJsonPath('data.selected_scheduled_at', null)
        ->assertJsonPath('data.provisional_duration_minutes', $appointment->duration_minutes);

    $request = AppointmentRequest::query()->where('user_id', $user->id)->firstOrFail();

    expect($request->request_type)->toBe(AppointmentRequestKind::Reschedule)
        ->and($request->appointment_id)->toBe($appointment->id)
        ->and($request->original_scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z')
        ->and($request->selected_scheduled_at)->toBeNull()
        ->and($request->status)->toBe(AppointmentRequestStatus::Pending);

    $appointment->refresh();

    expect($appointment->scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z');
});

test('a rebooking request must belong to the authenticated patient', function (): void {
    $user = User::factory()->patient()->create();
    $otherUser = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($otherUser);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment))
        ->assertNotFound();

    expect(AppointmentRequest::query()->count())->toBe(0);
});

test('only one pending rebooking request can target an appointment', function (): void {
    $user = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($user);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment))
        ->assertCreated();

    $this->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment, [
        'scheduled_at' => '2026-07-14T11:00:00+08:00',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);

    expect(AppointmentRequest::query()->count())->toBe(1);
});

test('ordinary appointment requests remain new requests', function (): void {
    $user = User::factory()->patient()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'appointment_type_id' => $type->id,
            'scheduled_at' => '2026-07-14T10:00:00+08:00',
            'reason_for_visit' => 'Blurred vision',
        ])
        ->assertCreated()
        ->assertJsonPath('data.request_type', AppointmentRequestKind::New->value)
        ->assertJsonPath('data.appointment', null);
});

test('rebooking requests reject identity and referral fields', function (): void {
    $user = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($user);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment, [
            'identity' => [
                'phone' => '09171234567',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => '123 Main St',
            ],
            'referring_source' => 'Friend',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identity', 'referring_source']);

    expect(AppointmentRequest::query()->count())->toBe(0);
});

test('cancelling a rebooking request leaves the appointment unchanged and allows another request', function (): void {
    $user = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($user);

    $first = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment))
        ->assertCreated()
        ->json('data');

    $this->postJson("/api/v1/appointment-requests/{$first['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', AppointmentRequestStatus::Cancelled->value);

    $this->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment, [
        'scheduled_at' => '2026-07-14T11:00:00+08:00',
    ]))
        ->assertCreated();

    expect(AppointmentRequest::query()->where('status', AppointmentRequestStatus::Cancelled)->count())->toBe(1)
        ->and(AppointmentRequest::query()->where('status', AppointmentRequestStatus::Pending)->count())->toBe(1)
        ->and($appointment->fresh()->scheduled_at->toISOString())->toBe('2026-07-13T02:00:00.000000Z');
});

test('only scheduled future appointments can be rebooked', function (): void {
    $user = User::factory()->patient()->create();
    $cancelled = scheduledAppointmentFor($user, [
        'appointment_status_id' => AppointmentStatus::query()->where('name', 'cancelled')->value('id'),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($cancelled))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);

    expect(AppointmentRequest::query()->count())->toBe(0);
});

test('a rebooking cannot submit the existing appointment time', function (): void {
    $user = User::factory()->patient()->create();
    $appointment = scheduledAppointmentFor($user);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', rebookingRequestData($appointment, [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);

    expect(AppointmentRequest::query()->count())->toBe(0);
});
