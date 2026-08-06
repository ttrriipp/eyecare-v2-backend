<?php

use App\Filament\Clusters\Availability\Pages\OptometristHours;
use App\Models\ProviderHour;
use App\Models\User;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\TimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Assert;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

test('each weekday\'s provider hour fields are independently enabled', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    // Provider Hours previously fed all 28 fields into one flat Grid, so a
    // disabled day removed cells and reflowed every later day into the wrong
    // column. Each day now gets its own Grid, so toggling one day must not
    // affect another day's field state. Fields stay visible (rather than
    // hidden) so every day's row aligns, but are disabled when the day is off.
    Livewire::test(OptometristHours::class)
        ->set('selectedOptometristId', $optometrist->id)
        ->set('providerHours.2.enabled', false)
        ->set('providerHours.3.enabled', true)
        ->assertSchemaComponentExists(
            'providerHours.2.start_time',
            checkComponentUsing: function (TimePicker $component): bool {
                Assert::assertTrue($component->isDisabled());

                return true;
            },
        )
        ->assertSchemaComponentExists(
            'providerHours.3.start_time',
            checkComponentUsing: function (TimePicker $component): bool {
                Assert::assertFalse($component->isDisabled());

                return true;
            },
        );
});

test('loaded provider hours display as plain H:i strings, not Carbon instances', function () {
    $optometrist = User::factory()->optometrist()->create();
    ProviderHour::query()
        ->where('user_id', $optometrist->id)
        ->where('weekday', 1)
        ->update(['start_time' => '08:30', 'end_time' => '16:30']);
    $this->actingAs($optometrist);

    // ProviderHour casts start_time/end_time as datetime:H:i, which only
    // controls serialization — direct property access still returns a
    // Carbon instance. Binding that straight into the form leaves the
    // TimePicker unable to display a value at all.
    Livewire::test(OptometristHours::class)
        ->set('selectedOptometristId', $optometrist->id)
        ->assertSet('providerHours.1.start_time', '08:30')
        ->assertSet('providerHours.1.end_time', '16:30');
});

test('shows an empty state instead of a blank form when there are no optometrists', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(OptometristHours::class)
        ->assertSchemaComponentExists('no_optometrists')
        ->assertSchemaComponentHidden('selectedOptometristId');
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
