<?php

use App\Actions\Appointments\RescheduleAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AuditLog;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    Http::fake();
});

test('customer can reschedule their own pending appointment', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'scheduled_at' => now()->addDays(2),
    ]);
    $newTime = now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0);

    $response = $this->actingAs($customer)->postJson("/api/appointments/{$appointment->id}/reschedule", [
        'scheduled_at' => $newTime->toDateTimeString(),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $appointment->refresh();
    expect($appointment->status->name)->toBe('pending')
        ->and($appointment->scheduled_at->format('Y-m-d H:i:s'))->toBe($newTime->format('Y-m-d H:i:s'));
});

test('customer can reschedule their own confirmed appointment', function () {
    $customer = User::factory()->customer()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
});

test('customer can reschedule a pending appointment more than once', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
});

test('staff reschedule can keep a confirmed appointment confirmed', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDays(2)->setHour(10)->setMinute(0),
    ]);

    app(RescheduleAppointment::class)->handle(
        appointment: $appointment,
        scheduledAt: now()->next('Monday')->setHour(10)->setMinute(0),
        customerInitiated: false,
        rescheduleReason: 'Clinic schedule conflict',
    );

    expect($appointment->refresh()->status->name)->toBe('confirmed');
});

test('staff reschedule can keep a pending appointment pending while storing a reason', function () {
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pending->id,
        'scheduled_at' => now()->addDays(2)->setHour(10)->setMinute(0),
    ]);

    app(RescheduleAppointment::class)->handle(
        appointment: $appointment,
        scheduledAt: now()->next('Monday')->setHour(10)->setMinute(0),
        customerInitiated: false,
        rescheduleReason: 'Doctor unavailable',
    );

    expect($appointment->refresh())
        ->status->name->toBe('pending')
        ->last_reschedule_reason->toBe('Doctor unavailable');
});

test('staff reschedule can keep a confirmed appointment confirmed while storing a reason', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDays(2)->setHour(10)->setMinute(0),
    ]);

    app(RescheduleAppointment::class)->handle(
        appointment: $appointment,
        scheduledAt: now()->next('Monday')->setHour(10)->setMinute(0),
        customerInitiated: false,
        rescheduleReason: 'Clinic schedule conflict',
    );

    expect($appointment->refresh())
        ->status->name->toBe('confirmed')
        ->last_reschedule_reason->toBe('Clinic schedule conflict');
});

test('customer reschedule does not set a staff reschedule reason', function () {
    $customer = User::factory()->customer()->create();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.last_reschedule_reason', null);

    expect($appointment->refresh()->last_reschedule_reason)->toBeNull();
});

test('customer appointment response includes latest staff reschedule reason', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'last_reschedule_reason' => 'Doctor unavailable',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/appointments/{$appointment->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.last_reschedule_reason', 'Doctor unavailable');
});

test('reschedule creates an sms notification record', function () {
    $customer = User::factory()->customer()->create(['phone' => '09171234567']);
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $confirmed->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($customer)->postJson("/api/appointments/{$appointment->id}/reschedule", [
        'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
    ]);

    $this->assertDatabaseHas(SmsNotification::class, [
        'appointment_id' => $appointment->id,
        'event' => 'appointment_rescheduled',
    ]);
});

test('reschedule audit records the old and new scheduled times', function () {
    $customer = User::factory()->customer()->create();
    $oldTime = now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
    $newTime = now()->next('Monday')->setHour(11)->setMinute(0)->setSecond(0);
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'scheduled_at' => $oldTime,
    ]);

    $this->actingAs($customer)->postJson("/api/appointments/{$appointment->id}/reschedule", [
        'scheduled_at' => $newTime->toDateTimeString(),
    ])->assertOk();

    $audit = AuditLog::query()
        ->where('subject_type', $appointment->getMorphClass())
        ->where('subject_id', $appointment->id)
        ->where('action', 'appointment.rescheduled')
        ->firstOrFail();

    expect($audit->metadata)
        ->toMatchArray([
            'from' => $oldTime->toDateTimeString(),
            'to' => $newTime->toDateTimeString(),
        ]);
});

test('staff reschedule audit records the reason', function () {
    $oldTime = now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0);
    $newTime = now()->next('Monday')->setHour(11)->setMinute(0)->setSecond(0);
    $appointment = Appointment::factory()->create([
        'scheduled_at' => $oldTime,
    ]);

    app(RescheduleAppointment::class)->handle(
        appointment: $appointment,
        scheduledAt: $newTime,
        customerInitiated: false,
        rescheduleReason: 'Doctor unavailable',
    );

    $audit = AuditLog::query()
        ->where('subject_type', $appointment->getMorphClass())
        ->where('subject_id', $appointment->id)
        ->where('action', 'appointment.rescheduled')
        ->firstOrFail();

    expect($audit->metadata)
        ->toMatchArray([
            'from' => $oldTime->toDateTimeString(),
            'to' => $newTime->toDateTimeString(),
            'reason' => 'Doctor unavailable',
        ]);
});

test('customer cannot reschedule a completed appointment', function () {
    $customer = User::factory()->customer()->create();
    $completed = AppointmentStatus::query()->where('name', 'completed')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $completed->id,
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertUnprocessable();
});

test('customer cannot reschedule a cancelled appointment', function () {
    $customer = User::factory()->customer()->create();
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $cancelled->id,
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertUnprocessable();
});

test('customer cannot reschedule another customers appointment', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $other->id,
        'appointment_status_id' => $pending->id,
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
        ])
        ->assertForbidden();
});

test('reschedule rejects a past date', function () {
    $customer = User::factory()->customer()->create();
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => now()->subDay()->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
});

test('reschedule is rejected when the new slot conflicts with another appointment', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $originalDate = now()->next('Monday');
    $targetDate = now()->next('Tuesday');

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $originalDate->copy()->setTime(9, 0),
    ]);

    // Another appointment occupies 10:00-10:30 on the target day.
    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $targetDate->copy()->setTime(10, 0),
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => $targetDate->copy()->setTime(10, 15)->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
});

test('reschedule stale availability returns a structured slot unavailable response without side effects', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->firstOrFail();
    $originalDate = now()->next('Monday')->setTime(9, 0);
    $targetDate = now()->next('Tuesday')->setTime(10, 0);

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $originalDate,
    ]);

    Appointment::factory()->create([
        'appointment_status_id' => $confirmed->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $targetDate,
    ]);

    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => $targetDate->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SLOT_UNAVAILABLE')
        ->assertJsonPath('availability.date', $targetDate->toDateString())
        ->assertJsonPath('availability.visit_reason_id', $visitReason->id)
        ->assertJsonPath('availability.optometrist_id', null)
        ->assertJsonPath('availability.appointment_id', $appointment->id)
        ->assertJsonValidationErrors('scheduled_at');

    expect($appointment->refresh()->scheduled_at->format('Y-m-d H:i:s'))->toBe($originalDate->format('Y-m-d H:i:s'))
        ->and(SmsNotification::query()->where('appointment_id', $appointment->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('subject_id', $appointment->id)->where('action', 'appointment.rescheduled')->count())->toBe(0);
});

test('reschedule does not conflict with the appointments own original slot', function () {
    $customer = User::factory()->customer()->create();
    $visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $pending = AppointmentStatus::query()->where('name', 'pending')->firstOrFail();
    $appointmentDate = now()->next('Monday');

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'appointment_status_id' => $pending->id,
        'visit_reason_id' => $visitReason->id,
        'scheduled_at' => $appointmentDate->copy()->setTime(9, 0),
    ]);

    // Rescheduling to a slightly later time within the same visit reason's duration window
    // should not be blocked by the appointment's own current slot (ignoreId).
    $this->actingAs($customer)
        ->postJson("/api/appointments/{$appointment->id}/reschedule", [
            'scheduled_at' => $appointmentDate->copy()->setTime(9, 15)->toDateTimeString(),
        ])
        ->assertOk();
});

test('unauthenticated users cannot reschedule appointments', function () {
    $appointment = Appointment::factory()->create();

    $this->postJson("/api/appointments/{$appointment->id}/reschedule", [
        'scheduled_at' => now()->next('Monday')->setHour(10)->setMinute(0)->setSecond(0)->toDateTimeString(),
    ])->assertUnauthorized();
});
