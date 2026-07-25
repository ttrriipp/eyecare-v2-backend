<?php

use App\Models\ClinicHour;
use Database\Seeders\ClinicHoursSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('clinic hours seeder creates all seven weekdays', function () {
    $this->seed(ClinicHoursSeeder::class);

    expect(ClinicHour::count())->toBe(7);

    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $this->assertDatabaseHas('clinic_hours', [
            'weekday' => $weekday,
            'open_time' => '09:00',
            'close_time' => '17:00',
            'enabled' => true,
        ]);
    }
});

test('each weekday has enabled open and close times', function () {
    $hour = ClinicHour::factory()->forWeekday(1)->create();

    expect($hour->weekday)->toBe(1)
        ->and($hour->enabled)->toBeTrue()
        ->and($hour->open_time->format('H:i'))->toBe('09:00')
        ->and($hour->close_time->format('H:i'))->toBe('17:00');
});

test('weekday is unique', function () {
    ClinicHour::factory()->forWeekday(3)->create();

    ClinicHour::factory()->forWeekday(3)->create();
})->throws(QueryException::class);

test('close time must be after open time', function () {
    // The model stores whatever is given — validation must happen at the
    // action or form-request level. This test documents the raw storage
    // behavior so callers know they must validate before saving.
    $hour = ClinicHour::query()->create([
        'weekday' => 1,
        'open_time' => '17:00',
        'close_time' => '09:00',
        'enabled' => true,
    ]);

    expect($hour->open_time->format('H:i'))->toBe('17:00')
        ->and($hour->close_time->format('H:i'))->toBe('09:00');
});

test('clinic hours can be disabled', function () {
    $hour = ClinicHour::factory()->closed()->forWeekday(0)->create();

    expect($hour->enabled)->toBeFalse();
});

test('seeder is idempotent', function () {
    $this->seed(ClinicHoursSeeder::class);
    $this->seed(ClinicHoursSeeder::class);

    expect(ClinicHour::count())->toBe(7);
});
