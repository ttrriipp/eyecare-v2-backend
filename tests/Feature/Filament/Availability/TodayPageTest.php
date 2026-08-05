<?php

use App\Filament\Clusters\Availability\Pages\Today;
use App\Models\ClinicHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

test('renders the resolved status for a closed day', function () {
    Carbon::setTestNow('2026-08-10 08:00:00'); // a Monday
    ClinicHour::query()->where('weekday', 1)->update(['enabled' => false]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(Today::class)->assertSee('Closed');

    Carbon::setTestNow();
});

test('renders an upcoming closure override on the resolved day', function () {
    Carbon::setTestNow('2026-08-10 08:00:00'); // a Monday
    User::factory()->optometrist()->create();
    ScheduleOverride::factory()->clinicClosed()->create([
        'override_date' => today()->addDay()->toDateString(),
    ]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(Today::class)->assertSee('Closed');

    Carbon::setTestNow();
});

test('renders no optometrist available when the only optometrist is away', function () {
    Carbon::setTestNow('2026-08-10 08:00:00'); // a Monday
    $optometrist = User::factory()->optometrist()->create();
    ScheduleOverride::factory()->providerAbsence($optometrist)->create([
        'override_date' => today()->toDateString(),
    ]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(Today::class)->assertSee('No optometrist available');

    Carbon::setTestNow();
});
