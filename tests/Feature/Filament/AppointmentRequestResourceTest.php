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

test('pending requests appear before resolved requests in the default queue order', function () {
    $staff = User::factory()->staff()->create();
    $pending = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'created_at' => now()->subDays(2),
    ]);
    $accepted = AppointmentRequest::factory()->linked()->accepted()->create([
        'created_at' => now(),
    ]);
    $rejected = AppointmentRequest::factory()->linked()->rejected()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertCanSeeTableRecords([$pending, $accepted, $rejected], inOrder: true);
});

test('request queue shows all preferred times and submitted time', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create([
        'created_at' => now()->subHours(3),
    ]);

    $this->actingAs($staff);

    $preferredTimes = collect($request->getAllTimePreferences())
        ->map(fn (string $time, int $index): string => sprintf(
            '%s: %s',
            $index === 0 ? 'Primary' : "Alt {$index}",
            Carbon::parse($time)->format('M j, g:i A'),
        ))
        ->all();

    Livewire::test(ListAppointmentRequests::class)
        ->assertTableColumnStateSet('scheduled_at', $preferredTimes, record: $request)
        ->assertSee('Preferred Times')
        ->assertSee('Submitted')
        ->assertSee($request->created_at->format('M j, Y g:i A'));
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

test('optometrist can access appointment requests', function () {
    $optometrist = User::factory()->optometrist()->create();
    $this->actingAs($optometrist);

    $this->get(AppointmentRequestResource::getUrl('index'))
        ->assertSuccessful();
});

test('patient cannot access appointment requests', function () {
    $patient = User::factory()->patient()->create();
    $this->actingAs($patient);

    $this->get(AppointmentRequestResource::getUrl('index'))
        ->assertForbidden();
});

test('expired pending requests do not expose terminal transition actions', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertActionHidden(TestAction::make('linkToPatient')->table($request))
        ->assertActionHidden(TestAction::make('reject')->table($request));
});

test('expired pending requests are not placed in actionable request tabs', function () {
    $staff = User::factory()->staff()->create();
    AppointmentRequest::factory()->create([
        'status' => AppointmentRequestStatus::Pending,
        'patient_id' => null,
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ListAppointmentRequests::class);
    $tabs = $component->instance()->getTabs();
    $needsLinkQuery = AppointmentRequest::query();
    $tabs['needs_link']->modifyQuery($needsLinkQuery);

    expect($needsLinkQuery->count())->toBe(0);
});
