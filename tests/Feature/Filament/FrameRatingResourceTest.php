<?php

use App\Filament\Resources\FrameRatings\FrameRatingResource;
use App\Filament\Resources\FrameRatings\Pages\EditFrameRating;
use App\Filament\Resources\FrameRatings\Pages\ListFrameRatings;
use App\Models\FrameRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list frame ratings', function () {
    $staff = User::factory()->staff()->create();
    $ratings = FrameRating::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListFrameRatings::class)
        ->assertCanSeeTableRecords($ratings)
        ->assertSee('Total ratings')
        ->assertSee('Average rating')
        ->assertSee('Low ratings')
        ->assertTableColumnExists('patient.full_name')
        ->assertTableColumnExists('created_at')
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
    $commentConsentAt = Carbon::parse('2026-09-20 10:00:00');
    $photoConsentAt = Carbon::parse('2026-09-21 14:30:00');
    $rating = FrameRating::factory()->create([
        'rating' => 5,
        'comment' => 'Comfortable and lightweight.',
        'public_display_consent_at' => $commentConsentAt,
        'attachment_path' => 'ratings/public-review.png',
        'public_attachment_consent_at' => $photoConsentAt,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditFrameRating::class, ['record' => $rating->getRouteKey()])
        ->assertSuccessful()
        ->assertSee("Rating of {$rating->patient->full_name}")
        ->assertDontSee("Order for {$rating->patient->full_name}")
        ->assertSee('Product context')
        ->assertSee('Product feedback')
        ->assertSee($rating->patient->full_name)
        ->assertSee($rating->variant->product->name)
        ->assertSee('5 of 5 stars')
        ->assertSee('Comfortable and lightweight.')
        ->assertSee('Public comment display consent')
        ->assertSee('Granted on '.$commentConsentAt->format('M j, Y g:i A'))
        ->assertSee('Public photo display consent')
        ->assertSee('Granted on '.$photoConsentAt->format('M j, Y g:i A'))
        ->assertSee('Submitted')
        ->assertDontSee('Moderation reason')
        ->assertDontSee('Moderated')
        ->assertDontSee('Save changes');
});

test('staff can see when public display consent was not granted', function () {
    $staff = User::factory()->staff()->create();
    $rating = FrameRating::factory()->create([
        'comment' => 'Private review.',
        'attachment_path' => 'ratings/private-review.png',
        'attachment_mime_type' => 'image/png',
        'public_display_consent_at' => null,
        'public_attachment_consent_at' => null,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditFrameRating::class, ['record' => $rating->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Public comment display consent')
        ->assertSee('Public photo display consent')
        ->assertSee('Not granted');
});
