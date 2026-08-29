<?php

use App\Filament\Clusters\Reports\Pages\FinancialReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Models\User;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('reports are available only to administrators', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(ReportsCluster::canAccess())
        ->toBeTrue()
        ->and(FinancialReport::canAccess())
        ->toBeTrue();

    foreach (['staff', 'optometrist', 'patient'] as $state) {
        $user = User::factory()->{$state}()->create();
        $this->actingAs($user);

        expect(ReportsCluster::canAccess())
            ->toBeFalse()
            ->and(FinancialReport::canAccess())
            ->toBeFalse();
    }
});

test('reports use one admin navigation entry with four internal destinations', function () {
    expect(ReportsCluster::getNavigationLabel())
        ->toBe('Reports')
        ->and(ReportsCluster::getSubNavigationPosition())
        ->toBe(SubNavigationPosition::Top)
        ->and(FinancialReport::getCluster())
        ->toBe(ReportsCluster::class);
});

test('reports default to the current Manila month and defer manual range changes', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(FinancialReport::class);

    expect($component->instance()->dateFrom)
        ->toBe('2026-08-01')
        ->and($component->instance()->dateUntil)
        ->toBe('2026-08-30')
        ->and($component->instance()->activePreset)
        ->toBe('this_month');

    $component
        ->set('dateInputFrom', '2026-07-01')
        ->set('dateInputUntil', '2026-07-31');

    expect($component->instance()->dateFrom)->toBe('2026-08-01');

    $component->call('applyDateRange');

    expect($component->instance()->dateFrom)
        ->toBe('2026-07-01')
        ->and($component->instance()->dateUntil)
        ->toBe('2026-07-31')
        ->and($component->instance()->activePreset)
        ->toBeNull();
});

test('reports reject malformed and reversed manual ranges without changing the applied range', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(FinancialReport::class)
        ->set('dateInputFrom', '2026-09-01')
        ->set('dateInputUntil', '2026-08-01')
        ->call('applyDateRange')
        ->assertHasErrors(['dateInputFrom']);

    expect($component->instance()->dateFrom)
        ->toBe('2026-08-01')
        ->and($component->instance()->dateUntil)
        ->toBe('2026-08-30');

    $component
        ->set('dateInputFrom', 'not-a-date')
        ->set('dateInputUntil', '2026-08-30')
        ->call('applyDateRange')
        ->assertHasErrors(['dateInputFrom']);
});

test('all report presets expose the approved labels', function () {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test(FinancialReport::class);

    expect($component->instance()->getPresets())->toBe([
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30' => 'Last 30 days',
        'this_year' => 'This year',
        'all_time' => 'All time',
    ]);
});
