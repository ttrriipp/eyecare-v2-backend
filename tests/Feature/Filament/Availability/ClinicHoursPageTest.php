<?php

use App\Filament\Clusters\Availability\Pages\ClinicHours;
use App\Models\ClinicHour;
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

test('each weekday\'s clinic hour fields are independently enabled', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // One Grid per weekday means toggling one day off must not disable or
    // reflow any other day's fields. Fields stay visible (so every day's row
    // aligns) but are disabled when the day is off.
    Livewire::test(ClinicHours::class)
        ->set('clinicHours.0.enabled', false)
        ->set('clinicHours.1.enabled', true)
        ->assertSchemaComponentExists(
            'clinicHours.0.open_time',
            checkComponentUsing: function (TimePicker $component): bool {
                Assert::assertTrue($component->isDisabled());

                return true;
            },
        )
        ->assertSchemaComponentExists(
            'clinicHours.1.open_time',
            checkComponentUsing: function (TimePicker $component): bool {
                Assert::assertFalse($component->isDisabled());

                return true;
            },
        );
});

test('an invalid day does not partially save clinic hours', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ClinicHours::class)
        ->set('clinicHours.1.enabled', true)
        ->set('clinicHours.1.open_time', '08:00')
        ->set('clinicHours.1.close_time', '18:00')
        ->set('clinicHours.2.enabled', true)
        ->set('clinicHours.2.open_time', '10:00')
        ->set('clinicHours.2.close_time', '08:00') // invalid: open after close
        ->call('saveClinicHours');

    // Monday's valid change must not have been committed either, since the
    // whole save is one transaction.
    expect(ClinicHour::query()->where('weekday', 1)->first()->open_time->format('H:i'))
        ->not->toBe('08:00');
});

test('a valid save updates every day', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ClinicHours::class)
        ->set('clinicHours.1.enabled', true)
        ->set('clinicHours.1.open_time', '08:00')
        ->set('clinicHours.1.close_time', '18:00')
        ->call('saveClinicHours');

    expect(ClinicHour::query()->where('weekday', 1)->first()->open_time->format('H:i'))->toBe('08:00');
});
