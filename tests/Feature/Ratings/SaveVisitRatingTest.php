<?php

use App\Actions\Ratings\SaveVisitRating;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitRating;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->patient = Patient::factory()->create();
    $this->appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $this->patient->id,
    ]);
});

test('a fulfilled appointment can be rated', function () {
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 5,
        comment: 'Excellent visit!',
    );

    expect($rating->rating)->toBe(5)
        ->and($rating->comment)->toBe('Excellent visit!')
        ->and($rating->patient_id)->toBe($this->patient->id)
        ->and($rating->appointment_id)->toBe($this->appointment->id);
});

test('re-submitting updates the rating in place', function () {
    // First rating
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 4,
        comment: 'Good visit',
    );

    // Revised rating
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 5,
        comment: 'Even better!',
    );

    expect($rating->rating)->toBe(5)
        ->and($rating->comment)->toBe('Even better!');

    // Only one record exists
    expect(VisitRating::where('appointment_id', $this->appointment->id)->count())->toBe(1);
});

test('non-fulfilled appointment is rejected', function () {
    $appointment = Appointment::factory()->create([
        'patient_id' => $this->patient->id,
    ]);

    expect(fn () => app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $appointment,
        rating: 5,
    ))->toThrow(ValidationException::class);
});

test('rating outside 1-5 is rejected', function () {
    expect(fn () => app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 0,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 6,
    ))->toThrow(ValidationException::class);
});

test('optometrist is snapshotted on create', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->create([
        'appointment_id' => $this->appointment->id,
        'patient_id' => $this->patient->id,
        'optometrist_id' => $optometrist->id,
    ]);

    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 5,
    );

    expect($rating->optometrist_id)->toBe($optometrist->id);
});

test('optometrist is not recomputed on revise', function () {
    $optometrist1 = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->create([
        'appointment_id' => $this->appointment->id,
        'patient_id' => $this->patient->id,
        'optometrist_id' => $optometrist1->id,
    ]);

    // First rating
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 4,
    );

    // Change the encounter's optometrist
    $optometrist2 = User::factory()->optometrist()->create();
    $encounter->update(['optometrist_id' => $optometrist2->id]);

    // Revise the rating
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 5,
    );

    // Optometrist should still be the original
    expect($rating->optometrist_id)->toBe($optometrist1->id);
});

test('unique constraint prevents duplicate ratings per appointment', function () {
    // Create first rating
    app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 5,
    );

    // Second call should update, not create a new record
    $rating = app(SaveVisitRating::class)->handle(
        patient: $this->patient,
        appointment: $this->appointment,
        rating: 4,
    );

    expect(VisitRating::where('appointment_id', $this->appointment->id)->count())->toBe(1);
});
