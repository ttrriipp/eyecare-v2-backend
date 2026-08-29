<?php

use App\Filament\Clusters\Reports\Pages\FeedbackReport;
use App\Models\FrameRating;
use App\Models\User;
use App\Models\VisitRating;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('feedback report includes hidden star values while excluding comments and out-of-period ratings', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));

    VisitRating::factory()->create([
        'rating' => 5,
        'comment' => 'Visible visit comment',
        'created_at' => '2026-08-01 09:00:00',
    ]);
    VisitRating::factory()->hidden()->create([
        'rating' => 1,
        'comment' => 'Hidden visit comment',
        'created_at' => '2026-08-10 09:00:00',
    ]);
    VisitRating::factory()->create([
        'rating' => 3,
        'comment' => 'Boundary visit comment',
        'created_at' => '2026-08-30 23:59:59',
    ]);
    VisitRating::factory()->create([
        'rating' => 5,
        'created_at' => '2026-08-31 00:00:00',
    ]);

    FrameRating::factory()->create([
        'rating' => 4,
        'comment' => 'Visible frame comment',
        'created_at' => '2026-08-15 09:00:00',
    ]);
    FrameRating::factory()->create([
        'rating' => 2,
        'is_hidden' => true,
        'comment' => 'Hidden frame comment',
        'created_at' => '2026-08-20 09:00:00',
    ]);
    FrameRating::factory()->create([
        'rating' => 5,
        'created_at' => '2026-07-31 23:59:59',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(FeedbackReport::class);
    $stats = collect($component->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Visit responses')?->getValue())
        ->toBe('3')
        ->and($stats->get('Visit average')?->getValue())
        ->toBe('3.0 / 5')
        ->and($stats->get('Frame responses')?->getValue())
        ->toBe('2')
        ->and($stats->get('Frame average')?->getValue())
        ->toBe('3.0 / 5');

    $sections = collect($component->instance()->getSections())->keyBy('title');
    $visitRows = collect($sections->get('Visit rating distribution')['rows'])->keyBy('label');
    $frameRows = collect($sections->get('Frame rating distribution')['rows'])->keyBy('label');

    expect($visitRows->get('1 star')['value'])
        ->toBe(1)
        ->and($visitRows->get('3 stars')['value'])
        ->toBe(1)
        ->and($visitRows->get('5 stars')['value'])
        ->toBe(1)
        ->and($frameRows->get('2 stars')['value'])
        ->toBe(1)
        ->and($frameRows->get('4 stars')['value'])
        ->toBe(1)
        ->and($component)
        ->assertDontSee('Visible visit comment')
        ->assertDontSee('Hidden visit comment')
        ->assertDontSee('Visible frame comment')
        ->assertDontSee('Hidden frame comment');
});

test('feedback report shows zero averages when a rating type has no responses', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    VisitRating::factory()->create([
        'rating' => 4,
        'created_at' => '2026-08-30 09:00:00',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $stats = collect(Livewire::test(FeedbackReport::class)->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Visit average')?->getValue())
        ->toBe('4.0 / 5')
        ->and($stats->get('Frame responses')?->getValue())
        ->toBe('0')
        ->and($stats->get('Frame average')?->getValue())
        ->toBe('0.0 / 5');
});
