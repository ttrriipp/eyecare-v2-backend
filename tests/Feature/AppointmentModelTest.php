<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentTypeSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('appointment factory creates valid records with required attributes', function () {
    $appointment = Appointment::factory()->create([
        'contact_notes' => 'Please call before arrival.',
        'staff_notes' => 'Needs dilation.',
    ]);

    expect($appointment->patient_id)->not->toBeNull()
        ->and($appointment->appointment_type_id)->not->toBeNull()
        ->and($appointment->duration_minutes)->not->toBeNull()
        ->and($appointment->appointment_status_id)->not->toBeNull()
        ->and($appointment->source)->toBe('manual')
        ->and($appointment->optometrist_id)->toBeNull()
        ->and($appointment->checked_in_at)->toBeNull()
        ->and($appointment->completed_at)->toBeNull()
        ->and($appointment->scheduled_at)->not->toBeNull()
        ->and($appointment->contact_notes)->toBe('Please call before arrival.')
        ->and($appointment->staff_notes)->toBe('Needs dilation.')
        ->and($appointment->patient)->toBeInstanceOf(Patient::class)
        ->and($appointment->appointmentType)->toBeInstanceOf(AppointmentType::class)
        ->and($appointment->status)->toBeInstanceOf(AppointmentStatus::class);
});

test('appointment relationships are typed', function () {
    $appointment = new Appointment;

    expect($appointment->patient())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->optometrist())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->appointmentType())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->status())->toBeInstanceOf(BelongsTo::class)
        ->and(method_exists($appointment, 'visitReason'))->toBeFalse()
        ->and((new AppointmentType)->appointments())->toBeInstanceOf(HasMany::class);
});

test('appointment workflow timestamps and optometrist capability use native casts', function () {
    $optometrist = User::factory()->staff()->create([
        'is_optometrist' => true,
    ]);
    $appointment = Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'checked_in_at' => now()->subMinutes(20),
        'completed_at' => now(),
    ]);

    expect($optometrist->is_optometrist)->toBeTrue()
        ->and($appointment->optometrist->is($optometrist))->toBeTrue()
        ->and($appointment->checked_in_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($appointment->completed_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('appointment types are seeded idempotently', function () {
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);

    expect(AppointmentType::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'Follow-up',
            'New Patient',
            'Referral',
            'Routine Check-up',
        ])
        ->and(AppointmentType::query()->count())->toBe(4);
});
