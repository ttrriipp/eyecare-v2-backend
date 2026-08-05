<?php

use App\Actions\Appointments\ResolveDailyAvailabilitySummary;
use App\Models\ClinicHour;
use App\Models\ProviderHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 08:00:00'); // a Monday
    ClinicHour::factory()->create(['weekday' => 1, 'enabled' => true, 'open_time' => '09:00', 'close_time' => '17:00']);
});

afterEach(fn () => Carbon::setTestNow());

test('resolves the requested number of days starting from the given date', function () {
    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 8);

    expect($summary)->toHaveCount(8)
        ->and($summary->first()->date->toDateString())->toBe(today()->toDateString())
        ->and($summary->last()->date->toDateString())->toBe(today()->addDays(7)->toDateString());
});

test('a day the clinic is closed resolves to closed regardless of optometrists', function () {
    ClinicHour::query()->where('weekday', 1)->update(['enabled' => false]);
    User::factory()->optometrist()->create();

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);

    expect($summary->first()->status)->toBe('closed')
        ->and($summary->first()->optometristStatuses)->toBe([]);
});

test('an open day with an available optometrist resolves to open', function () {
    $optometrist = User::factory()->optometrist()->create(); // auto-creates 09:00-17:00 provider hours for all weekdays

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);
    $day = $summary->first();

    expect($day->status)->toBe('open')
        ->and($day->optometristStatuses)->toHaveCount(1)
        ->and($day->optometristStatuses[0]->status)->toBe('in')
        ->and($day->optometristStatuses[0]->startTime)->toBe('09:00')
        ->and($day->optometristStatuses[0]->endTime)->toBe('17:00');
});

test('the only optometrist absent all day resolves to no optometrist available, not open', function () {
    $optometrist = User::factory()->optometrist()->create();

    ScheduleOverride::factory()->providerAbsence($optometrist)->create([
        'override_date' => today()->toDateString(),
    ]);

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);
    $day = $summary->first();

    expect($day->status)->toBe('no_optometrist')
        ->and($day->optometristStatuses[0]->status)->toBe('away_full');
});

test('the only optometrist not scheduled that weekday resolves to no optometrist available', function () {
    $optometrist = User::factory()->optometrist()->create();
    ProviderHour::query()->where('user_id', $optometrist->id)->where('weekday', 1)->update(['enabled' => false]);

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);
    $day = $summary->first();

    expect($day->status)->toBe('no_optometrist')
        ->and($day->optometristStatuses[0]->status)->toBe('not_scheduled');
});

test('a partial-day absence still counts as available for the day', function () {
    $optometrist = User::factory()->optometrist()->create();

    ScheduleOverride::factory()->create([
        'user_id' => $optometrist->id,
        'type' => 'provider_absence',
        'override_date' => today()->toDateString(),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'reason' => 'Dental appointment',
    ]);

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);
    $day = $summary->first();

    expect($day->status)->toBe('open')
        ->and($day->optometristStatuses[0]->status)->toBe('away_partial')
        ->and($day->optometristStatuses[0]->startTime)->toBe('09:00')
        ->and($day->optometristStatuses[0]->endTime)->toBe('12:00')
        ->and($day->optometristStatuses[0]->reason)->toBe('Dental appointment');
});

test('the clinic stays open when at least one of several optometrists is available', function () {
    $away = User::factory()->optometrist()->create();
    $in = User::factory()->optometrist()->create();

    ScheduleOverride::factory()->providerAbsence($away)->create([
        'override_date' => today()->toDateString(),
    ]);

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);
    $day = $summary->first();

    expect($day->status)->toBe('open')
        ->and($day->optometristStatuses)->toHaveCount(2);
});

test('an early close override is surfaced on the resolved day', function () {
    ScheduleOverride::factory()->earlyClose('14:00')->create([
        'override_date' => today()->toDateString(),
    ]);
    User::factory()->optometrist()->create();

    $summary = app(ResolveDailyAvailabilitySummary::class)->handle(today(), days: 1);

    expect($summary->first()->earlyCloseTime)->toBe('14:00');
});
