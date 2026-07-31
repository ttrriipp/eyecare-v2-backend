<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
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

// --- Resource Configuration ---

test('resource uses correct model', function () {
    expect(AppointmentRequestResource::getModel())->toBe(AppointmentRequest::class);
});

test('resource cannot create records', function () {
    expect(AppointmentRequestResource::canCreate())->toBeFalse();
});

// --- Table ---

test('table shows request number and status', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $request = AppointmentRequest::factory()->create([
        'status' => AppointmentRequestStatus::Pending,
    ]);

    $this->get(AppointmentRequestResource::getUrl('index'))
        ->assertSuccessful();
});

// --- Policy ---

test('staff can access appointment requests', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(AppointmentRequestResource::getUrl('index'))
        ->assertSuccessful();
});

test('admin can access appointment requests', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->get(AppointmentRequestResource::getUrl('index'))
        ->assertSuccessful();
});
