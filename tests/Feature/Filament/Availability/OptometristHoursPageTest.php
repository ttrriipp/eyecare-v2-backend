<?php

use App\Filament\Clusters\Availability\Pages\OptometristHours;
use App\Models\ProviderHour;
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

test('each weekday\'s provider hour fields are independently visible', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    // Provider Hours previously fed all 28 fields into one flat Grid, so a
    // disabled day removed cells and reflowed every later day into the wrong
    // column. Each day now gets its own Grid, so toggling one day must not
    // affect another day's field visibility.
    Livewire::test(OptometristHours::class)
        ->set('selectedOptometristId', $optometrist->id)
        ->set('providerHours.2.enabled', false)
        ->set('providerHours.3.enabled', true)
        ->assertSchemaComponentHidden('providerHours.2.start_time')
        ->assertSchemaComponentHidden('providerHours.2.end_time')
        ->assertSchemaComponentVisible('providerHours.3.start_time')
        ->assertSchemaComponentVisible('providerHours.3.end_time');
});

test('provider hours outside clinic hours are rejected with a visible error', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    Livewire::test(OptometristHours::class)
        ->set('selectedOptometristId', $optometrist->id)
        ->set('providerHours.1.enabled', true)
        ->set('providerHours.1.start_time', '07:00')
        ->set('providerHours.1.end_time', '10:00')
        ->call('saveProviderHours')
        ->assertNotified();

    expect(ProviderHour::query()->where('user_id', $optometrist->id)->where('weekday', 1)->first()->start_time->format('H:i'))
        ->not->toBe('07:00');
});
