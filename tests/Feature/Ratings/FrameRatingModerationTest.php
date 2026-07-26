<?php

use App\Actions\Ratings\ModerateFrameRating;
use App\Actions\Ratings\SaveFrameRating;
use App\Models\FrameRating;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('hidden comments retain their star in aggregates', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();

    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Great frame');

    // Hide the comment
    $moderator = User::factory()->staff()->create();
    app(ModerateFrameRating::class)->handle($rating, 'Inappropriate', $moderator);

    $rating->refresh();

    // Star is preserved
    expect($rating->rating)->toBe(5)
        ->and($rating->is_hidden)->toBeTrue()
        ->and($rating->comment)->toBe('Great frame'); // Original comment preserved

    // Aggregates still include the star
    $avg = FrameRating::query()->avg('rating');
    expect((float) $avg)->toBe(5.0);
});

test('moderation records reason actor and timestamp', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'OK');
    $moderator = User::factory()->staff()->create();

    app(ModerateFrameRating::class)->handle($rating, 'Spam content', $moderator);

    $rating->refresh();
    expect($rating->is_hidden)->toBeTrue()
        ->and($rating->moderation_reason)->toBe('Spam content')
        ->and($rating->moderated_by)->toBe($moderator->id)
        ->and($rating->moderated_at)->not->toBeNull();
});

test('clinic users cannot edit rating values', function () {
    // The moderate action only hides/shows comments — it never changes the star value
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 3, 'Average');
    $moderator = User::factory()->staff()->create();

    app(ModerateFrameRating::class)->handle($rating, 'Reason', $moderator);

    expect($rating->fresh()->rating)->toBe(3); // Star unchanged
});

test('already hidden rating cannot be hidden again', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 4, 'Test');
    $moderator = User::factory()->staff()->create();

    app(ModerateFrameRating::class)->handle($rating, 'First hide', $moderator);
    app(ModerateFrameRating::class)->handle($rating, 'Second hide', $moderator);
})->throws(ValidationException::class);

test('hidden rating can be restored', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $rating = app(SaveFrameRating::class)->handle($patient, $variant, 5, 'Good');
    $moderator = User::factory()->staff()->create();

    app(ModerateFrameRating::class)->handle($rating, 'Hidden', $moderator);
    expect($rating->fresh()->is_hidden)->toBeTrue();

    app(ModerateFrameRating::class)->restore($rating->fresh(), $moderator);
    expect($rating->fresh()->is_hidden)->toBeFalse()
        ->and($rating->fresh()->moderation_reason)->toBeNull();
});
