<?php

use App\Actions\Appointments\ExpireAppointmentRequests;
use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('expired pending requests are marked as expired', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subHour(), // Already expired
    ]);

    $expired = app(ExpireAppointmentRequests::class)->handle();

    expect($expired)->toBe(1);
    expect(AppointmentRequest::first()->status)->toBe(AppointmentRequestStatus::Expired);
});

test('non-expired pending requests are not affected', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addHours(24), // Not expired yet
    ]);

    $expired = app(ExpireAppointmentRequests::class)->handle();

    expect($expired)->toBe(0);
    expect(AppointmentRequest::first()->status)->toBe(AppointmentRequestStatus::Pending);
});

test('already terminal requests are not affected', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->accepted()->create([
        'user_id' => $user->id,
        'expires_at' => now()->subHour(),
    ]);

    AppointmentRequest::factory()->cancelled()->create([
        'user_id' => $user->id,
        'expires_at' => now()->subHour(),
    ]);

    $expired = app(ExpireAppointmentRequests::class)->handle();

    expect($expired)->toBe(0);
});

test('command runs successfully', function () {
    $this->artisan('appointments:expire-requests')
        ->assertSuccessful();
});
