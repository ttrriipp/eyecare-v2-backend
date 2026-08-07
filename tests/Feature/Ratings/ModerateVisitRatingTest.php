<?php

use App\Actions\Ratings\ModerateVisitRating;
use App\Models\User;
use App\Models\VisitRating;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->moderator = User::factory()->create();
    $this->rating = VisitRating::factory()->create();
});

test('a rating comment can be hidden', function () {
    $rating = app(ModerateVisitRating::class)->handle(
        rating: $this->rating,
        reason: 'Inappropriate content',
        moderator: $this->moderator,
    );

    expect($rating->is_hidden)->toBeTrue()
        ->and($rating->moderation_reason)->toBe('Inappropriate content')
        ->and($rating->moderated_by)->toBe($this->moderator->id)
        ->and($rating->moderated_at)->not->toBeNull();

    // Star value is preserved
    expect($rating->rating)->toBe($this->rating->rating);
});

test('hiding an already hidden rating throws', function () {
    $this->rating->update(['is_hidden' => true]);

    expect(fn () => app(ModerateVisitRating::class)->handle(
        rating: $this->rating,
        reason: 'Another reason',
        moderator: $this->moderator,
    ))->toThrow(ValidationException::class);
});

test('a hidden rating can be restored', function () {
    $this->rating->update([
        'is_hidden' => true,
        'moderation_reason' => 'Test',
        'moderated_by' => $this->moderator->id,
        'moderated_at' => now(),
    ]);

    $rating = app(ModerateVisitRating::class)->restore(
        rating: $this->rating,
        moderator: $this->moderator,
    );

    expect($rating->is_hidden)->toBeFalse()
        ->and($rating->moderation_reason)->toBeNull();
});

test('restoring a non-hidden rating is a no-op', function () {
    expect($this->rating->is_hidden)->toBeFalse();

    $rating = app(ModerateVisitRating::class)->restore(
        rating: $this->rating,
        moderator: $this->moderator,
    );

    expect($rating->is_hidden)->toBeFalse()
        ->and($rating->id)->toBe($this->rating->id);
});

test('the rating integer is never mutated by moderation', function () {
    $originalRating = $this->rating->rating;

    $rating = app(ModerateVisitRating::class)->handle(
        rating: $this->rating,
        reason: 'Test',
        moderator: $this->moderator,
    );

    expect($rating->rating)->toBe($originalRating);
});
