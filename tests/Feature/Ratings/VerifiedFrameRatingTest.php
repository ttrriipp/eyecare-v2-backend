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
        ->and($rating->dispensing_event_id)->toBe($dispensingEvent->id)
        ->and($rating->current_revision_id)->not->toBeNull();
});

test('one current rating per patient per dispensed frame is enforced', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    // First rating creates a new record
    $first = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'Good');
    expect($first->revisions)->toHaveCount(1);

    // Second rating for same patient+variant appends a revision (not a new record)
    $second = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Great');
    $second->load('revisions');

    expect($second->id)->toBe($first->id) // Same record
        ->and($second->rating)->toBe(5)
        ->and($second->revisions)->toHaveCount(2); // Revision appended

    // DB unique constraint still prevents direct bypass
    expect(fn () => FrameRating::factory()->create([
        'patient_id' => $patient->id,
        'product_variant_id' => $variant->id,
    ]))->toThrow(QueryException::class);
});

test('edits append attributable revisions', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'Good frame');
    expect($rating->revisions)->toHaveCount(1)
        ->and($rating->rating)->toBe(4);

    // Edit the rating
    $updated = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Updated comment');
    $updated->load('revisions');

    expect($updated->revisions)->toHaveCount(2)
        ->and($updated->rating)->toBe(5)
        ->and($updated->comment)->toBe('Updated comment')
        ->and($updated->currentRevision->revision_number)->toBe(2)
        ->and($updated->currentRevision->rating)->toBe(5);
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

test('initial revision is numbered 1', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 3, 'Average');

    expect($rating->revisions->first()->revision_number)->toBe(1)
        ->and($rating->revisions->first()->rating)->toBe(3);
});

test('revisions retain previous rating values', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 3, 'Okay');
    app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Great!');

    $rating->load('revisions');

    expect($rating->revisions->first()->rating)->toBe(3)
        ->and($rating->revisions->last()->rating)->toBe(5);
});
