<?php

use App\Actions\Ratings\SaveFrameRating;
use App\Models\DispensingEvent;
use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('patient can rate a dispensed frame', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $dispensingEvent = DispensingEvent::factory()->create();

    $rating = app(SaveFrameRating::class)->handle(
        patient: $patient,
        variant: $variant,
        rating: 5,
        comment: 'Excellent frame!',
        dispensingEvent: $dispensingEvent,
    );

    expect($rating->rating)->toBe(5)
        ->and($rating->comment)->toBe('Excellent frame!')
        ->and($rating->patient_id)->toBe($patient->id)
        ->and($rating->product_variant_id)->toBe($variant->id)
        ->and($rating->dispensing_event_id)->toBe($dispensingEvent->id);
});

test('one current rating per patient per dispensed frame is enforced', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    // First rating creates a new record
    $first = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'Good');

    // Second rating for same patient+variant updates in place
    $second = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Great');

    expect($second->id)->toBe($first->id) // Same record
        ->and($second->rating)->toBe(5)
        ->and($second->comment)->toBe('Great');

    // DB unique constraint still prevents direct bypass
    expect(fn () => FrameRating::factory()->create([
        'patient_id' => $patient->id,
        'product_variant_id' => $variant->id,
    ]))->toThrow(QueryException::class);
});

test('edits update the rating in place', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'Good frame');
    expect($rating->rating)->toBe(4);

    // Edit the rating
    $updated = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Updated comment');

    expect($updated->rating)->toBe(5)
        ->and($updated->comment)->toBe('Updated comment');
});

test('rating must be between 1 and 5', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    app(SaveFrameRating::class)->handle($patient, $variant, 0, 'Bad');
})->throws(ValidationException::class);

test('rating must be between 1 and 5 upper bound', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    app(SaveFrameRating::class)->handle($patient, $variant, 6, 'Too high');
})->throws(ValidationException::class);
