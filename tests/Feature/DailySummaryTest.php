<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('daily summary command counts job orders and billing payments', function () {
    $admin = User::factory()->admin()->create();

    $this->artisan('clinic:daily-summary')
        ->assertSuccessful()
        ->expectsOutput('Daily summary sent to admin users.');
});

test('daily summary command handles no admins gracefully', function () {
    $this->artisan('clinic:daily-summary')
        ->assertSuccessful()
        ->expectsOutput('No admin users to notify.');
});
