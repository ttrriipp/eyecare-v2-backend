<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\VisitReasonSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('appointment factory creates valid records with required attributes', function () {
    $appointment = Appointment::factory()->create([
        'contact_notes' => 'Please call before arrival.',
        'staff_notes' => 'Needs dilation.',
    ]);

    expect($appointment->customer_id)->not->toBeNull()
        ->and($appointment->visit_reason_id)->not->toBeNull()
        ->and($appointment->appointment_status_id)->not->toBeNull()
        ->and($appointment->source)->toBe('staff_created')
        ->and($appointment->optometrist_id)->toBeNull()
        ->and($appointment->checked_in_at)->toBeNull()
        ->and($appointment->completed_at)->toBeNull()
        ->and($appointment->scheduled_at)->not->toBeNull()
        ->and($appointment->contact_notes)->toBe('Please call before arrival.')
        ->and($appointment->staff_notes)->toBe('Needs dilation.')
        ->and($appointment->customer)->toBeInstanceOf(User::class)
        ->and($appointment->visitReason)->toBeInstanceOf(VisitReason::class)
        ->and($appointment->status)->toBeInstanceOf(AppointmentStatus::class);
});

test('appointment relationships are typed', function () {
    $appointment = new Appointment;

    expect($appointment->customer())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->optometrist())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->visitReason())->toBeInstanceOf(BelongsTo::class)
        ->and($appointment->status())->toBeInstanceOf(BelongsTo::class)
        ->and((new VisitReason)->appointments())->toBeInstanceOf(HasMany::class);
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

test('visit reasons are seeded idempotently', function () {
    $this->seed(VisitReasonSeeder::class);
    $this->seed(VisitReasonSeeder::class);

    expect(VisitReason::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'Contact Lens Fitting',
            'Eye Exam',
            'Follow-up',
            'Prescription Check',
        ])
        ->and(VisitReason::query()->count())->toBe(4);
});
