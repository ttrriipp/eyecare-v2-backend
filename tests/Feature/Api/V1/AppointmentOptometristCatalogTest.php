<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->patient()->create();
});

test('appointment optometrists endpoint requires authentication', function () {
    $this->getJson('/api/v1/appointment-optometrists')
        ->assertUnauthorized();
});

test('active optometrists are listed', function () {
    $optometrist = User::factory()->optometrist()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Santos',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $optometrist->id)
        ->assertJsonPath('data.0.name', 'Maria Santos');
});

test('inactive optometrists are excluded', function () {
    User::factory()->optometrist()->create(['is_active' => false]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('non-optometrist users are excluded', function () {
    User::factory()->staff()->create();
    User::factory()->admin()->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('dual-role admin optometrists are included', function () {
    $dualRole = User::factory()->admin()->create();
    $dualRole->roles()->attach(
        Role::where('name', 'optometrist')->firstOrFail()
    );

    $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('response contains only patient-safe fields', function () {
    User::factory()->optometrist()->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk();

    $optometrist = $response->json('data.0');

    expect($optometrist)->toHaveKeys(['id', 'name'])
        ->and($optometrist)->not->toHaveKey('email')
        ->and($optometrist)->not->toHaveKey('phone')
        ->and($optometrist)->not->toHaveKey('roles')
        ->and($optometrist)->not->toHaveKey('is_active');
});

test('optometrists are ordered by name', function () {
    User::factory()->optometrist()->create([
        'first_name' => 'Carlos',
        'middle_name' => '',
        'last_name' => 'Reyes',
    ]);
    User::factory()->optometrist()->create([
        'first_name' => 'Ana',
        'middle_name' => '',
        'last_name' => 'Santos',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointment-optometrists')
        ->assertOk();

    $names = collect($response->json('data'))->pluck('name')->toArray();

    // Should be sorted by last_name then first_name
    expect($names[0])->toBe('Carlos Reyes')
        ->and($names[1])->toBe('Ana Santos');
});
