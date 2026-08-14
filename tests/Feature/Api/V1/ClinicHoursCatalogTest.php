<?php

use App\Models\ClinicHour;
use App\Models\User;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

test('clinic hours endpoint requires authentication', function (): void {
    $this->getJson('/api/v1/clinic-hours')
        ->assertUnauthorized();
});

test('linked and unlinked accounts can list the full weekly schedule', function (): void {
    $this->actingAs(User::factory()->patient()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.weekday', 0)
        ->assertJsonPath('data.0.day_name', 'Sunday')
        ->assertJsonPath('data.1.weekday', 1)
        ->assertJsonPath('data.1.day_name', 'Monday')
        ->assertJsonPath('data.6.weekday', 6)
        ->assertJsonPath('data.6.day_name', 'Saturday');

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk()
        ->assertJsonCount(7, 'data');
});

test('clinic hours use Carbon weekday numbering and local HH:mm strings', function (): void {
    ClinicHour::query()->where('weekday', 2)->update([
        'open_time' => '08:30',
        'close_time' => '16:45',
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['weekday', 'day_name', 'enabled', 'open_time', 'close_time'],
            ],
        ])
        ->assertJsonPath('data.2.weekday', 2)
        ->assertJsonPath('data.2.day_name', 'Tuesday')
        ->assertJsonPath('data.2.open_time', '08:30')
        ->assertJsonPath('data.2.close_time', '16:45');
});

test('clinic hours always return all seven weekdays when a row is missing', function (): void {
    ClinicHour::query()->where('weekday', 6)->delete();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.6.weekday', 6)
        ->assertJsonPath('data.6.day_name', 'Saturday')
        ->assertJsonPath('data.6.enabled', true)
        ->assertJsonPath('data.6.open_time', '09:00')
        ->assertJsonPath('data.6.close_time', '17:00');
});

test('disabled clinic hours keep time keys present with null values', function (): void {
    ClinicHour::query()->where('weekday', 0)->update(['enabled' => false]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk();

    expect($response->json('data.0'))->toBe([
        'weekday' => 0,
        'day_name' => 'Sunday',
        'enabled' => false,
        'open_time' => null,
        'close_time' => null,
    ]);
});

test('clinic hours expose no internal fields', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/clinic-hours')
        ->assertOk();

    expect($response->json('data.0'))->not->toHaveKeys(['id', 'created_at', 'updated_at']);
});
