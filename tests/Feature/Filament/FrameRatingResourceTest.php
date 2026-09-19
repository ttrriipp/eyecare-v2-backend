<?php

use App\Filament\Resources\FrameRatings\FrameRatingResource;
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
        ->assertCanSeeTableRecords($ratings);
});

test('staff can see the original unsanitized frame feedback', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create([
        'comment' => 'This is PORN.',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListFrameRatings::class)
        ->assertSee('This is PORN.')
        ->assertDontSee('This is ****.');
});

test('frame rating resource is registered', function () {
    expect(FrameRatingResource::getModel())->toBe(FrameRating::class);
});

test('staff can open a frame rating from an actionable notification link', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create();

    $this->actingAs($staff)
        ->get(FrameRatingResource::getUrl('edit', ['record' => $rating], panel: 'admin'))
        ->assertSuccessful();
});
