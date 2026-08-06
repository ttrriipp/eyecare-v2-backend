<?php

use App\Filament\Clusters\Availability\Pages\ScheduleOverrides;
use App\Models\ScheduleOverride;
use App\Models\User;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

test('an admin can add a clinic closure override', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ScheduleOverrides::class)
        ->callAction('addOverride', [
            'type' => 'closed',
            'override_date' => today()->addDays(3)->toDateString(),
            'reason' => 'Holiday',
        ])
        ->assertNotified();

    expect(ScheduleOverride::query()->where('type', 'closed')->exists())->toBeTrue();
});

test('a non-admin optometrist cannot add a clinic-wide closure', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    // Non-admins are only offered "Provider Absence" as a type, so submitting
    // "closed" is a tampered value. Filament's own Select validation rejects
    // any value outside its options() list before our action code ever runs.
    Livewire::test(ScheduleOverrides::class)
        ->callAction('addOverride', [
            'type' => 'closed',
            'override_date' => today()->addDays(3)->toDateString(),
        ])
        ->assertHasErrors();

    expect(ScheduleOverride::query()->where('type', 'closed')->exists())->toBeFalse();
});

test('a non-admin optometrist can add their own absence', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    Livewire::test(ScheduleOverrides::class)
        ->callAction('addOverride', [
            'type' => 'provider_absence',
            'override_date' => today()->addDays(2)->toDateString(),
            'user_id' => $optometrist->id,
        ])
        ->assertNotified();

    expect(ScheduleOverride::query()
        ->where('type', 'provider_absence')
        ->where('user_id', $optometrist->id)
        ->exists())->toBeTrue();
});

test('a non-admin optometrist cannot add another optometrist\'s absence', function () {
    $optometrist = User::factory()->optometrist()->create();
    $otherOptometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    Livewire::test(ScheduleOverrides::class)
        ->callAction('addOverride', [
            'type' => 'provider_absence',
            'override_date' => today()->addDays(2)->toDateString(),
            'user_id' => $otherOptometrist->id,
        ])
        ->assertNotified();

    expect(ScheduleOverride::query()
        ->where('type', 'provider_absence')
        ->where('user_id', $otherOptometrist->id)
        ->exists())->toBeFalse();
});

test('an admin can remove an override', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $override = ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => today()->addDays(3)->toDateString(),
    ]);

    Livewire::test(ScheduleOverrides::class)
        ->call('deleteOverride', $override->id)
        ->assertNotified();

    expect(ScheduleOverride::query()->find($override->id))->toBeNull();
});

test('a non-admin optometrist cannot remove a clinic-wide override', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    $override = ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => today()->addDays(3)->toDateString(),
    ]);

    Livewire::test(ScheduleOverrides::class)
        ->call('deleteOverride', $override->id)
        ->assertNotified();

    expect(ScheduleOverride::query()->find($override->id))->not->toBeNull();
});

test('past overrides are not shown in the upcoming list', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $past = ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => today()->subDays(2)->toDateString(),
    ]);
    $upcoming = ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => today()->addDays(2)->toDateString(),
    ]);

    Livewire::test(ScheduleOverrides::class)
        ->assertCanSeeTableRecords([$upcoming])
        ->assertCanNotSeeTableRecords([$past]);
});
