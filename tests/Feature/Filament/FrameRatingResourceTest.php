<?php

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Filament\Resources\FrameRatings\Pages\EditFrameRating;
use App\Filament\Resources\FrameRatings\Pages\ListFrameRatings;
use App\Models\FrameRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list frame ratings', function () {
    $staff = User::factory()->staff()->create();
    $ratings = FrameRating::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListFrameRatings::class)
        ->assertCanSeeTableRecords($ratings)
        ->assertTableColumnDoesNotExist('comment')
        ->assertTableColumnDoesNotExist('is_hidden')
        ->assertTableColumnDoesNotExist('moderated_at')
        ->assertTableActionDoesNotExist('hideComment')
        ->assertTableActionDoesNotExist('restoreComment');
});

test('staff cannot see frame feedback comments in the ratings table', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create([
        'comment' => 'This is PORN.',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListFrameRatings::class)
        ->assertDontSee('This is PORN.')
        ->assertDontSee('This is ****.');
});

test('product rating resource is registered in admin navigation', function () {
    expect(FrameRatingResource::getModel())->toBe(FrameRating::class)
        ->and(FrameRatingResource::shouldRegisterNavigation())->toBeTrue()
        ->and(FrameRatingResource::getNavigationLabel())->toBe('Product Ratings');
});

test('staff can open a frame rating from an actionable notification link', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create();
    $url = FrameRatingResource::getUrl('edit', ['record' => $rating], panel: 'admin');

    expect($url)->toContain('/product-ratings/');

    $this->actingAs($staff)
        ->get($url)
        ->assertSuccessful();
});

test('staff can view frame rating details', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create([
        'rating' => 5,
        'comment' => 'Comfortable and lightweight.',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditFrameRating::class, ['record' => $rating->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($rating->patient->full_name)
        ->assertSee($rating->variant->product->name)
        ->assertSee('5 of 5 stars')
        ->assertSee('Comfortable and lightweight.')
        ->assertDontSee('Moderation reason')
        ->assertDontSee('Moderated')
        ->assertDontSee('Save changes');
});
