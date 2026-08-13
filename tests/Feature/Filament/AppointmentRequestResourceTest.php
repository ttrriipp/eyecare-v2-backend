<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Pages\ListAppointmentRequests;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

test('request queue does not expose a quick accept action', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertActionDoesNotExist(TestAction::make('accept')->table($request));
});

test('request queue shows scheduling context', function () {
    $staff = User::factory()->staff()->create();
    $type = AppointmentType::factory()->create([
        'name' => 'Internal Review',
        'patient_label' => 'Vision Review',
        'duration_minutes' => 45,
    ]);
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create([
        'appointment_type_id' => $type->id,
        'provisional_duration_minutes' => 45,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee('Vision Review')
        ->assertSee('Not requested');
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
