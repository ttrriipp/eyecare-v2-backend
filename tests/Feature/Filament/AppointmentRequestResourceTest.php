<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Pages\ListAppointmentRequests;
use App\Models\Appointment;
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

test('request queue separates viewing from scheduling actions', function () {
    $staff = User::factory()->staff()->create();
    $ready = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);
    $expired = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);
    $unlinked = AppointmentRequest::factory()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertActionVisible(TestAction::make('view')->table($ready))
        ->assertActionVisible(TestAction::make('reviewSchedule')->table($ready))
        ->assertActionHidden(TestAction::make('reviewSchedule')->table($expired))
        ->assertActionHidden(TestAction::make('reviewSchedule')->table($unlinked))
        ->assertSee('View request')
        ->assertSee('Review & Schedule')
        ->assertSee('href="'.AppointmentRequestResource::getUrl('schedule', ['record' => $ready]).'"', false);
});

test('expired pending requests display as expired in the request queue', function () {
    $staff = User::factory()->staff()->create();
    $expired = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee($expired->request_number)
        ->assertTableColumnFormattedStateSet('status', 'Expired', record: $expired);
});

test('status filters use the effective expired status', function () {
    $staff = User::factory()->staff()->create();
    $expiredPending = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);
    $activePending = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->filterTable('status', AppointmentRequestStatus::Expired->value)
        ->assertCanSeeTableRecords([$expiredPending])
        ->assertCanNotSeeTableRecords([$activePending])
        ->filterTable('status', AppointmentRequestStatus::Pending->value)
        ->assertCanSeeTableRecords([$activePending])
        ->assertCanNotSeeTableRecords([$expiredPending]);
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
    $expired = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
        'created_at' => now()->subDays(3),
    ]);
    $accepted = AppointmentRequest::factory()->linked()->accepted()->create([
        'created_at' => now(),
    ]);
    $rejected = AppointmentRequest::factory()->linked()->rejected()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertCanSeeTableRecords([$pending, $expired, $accepted, $rejected], inOrder: true);
});

test('request queue shows all preferred times and submitted time', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create([
        'created_at' => now()->subHours(3),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ListAppointmentRequests::class)
        ->assertSee('Preferred Times')
        ->assertSee('Submitted')
        ->assertSee($request->created_at->format('M j, g:i A'));

    foreach ($request->getAllTimePreferences() as $time) {
        $component->assertSee(Carbon::parse($time)->format('M j, g:i A'));
    }
});

test('request queue shows availability beside each pending preferred time', function () {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $primary = Carbon::parse('2026-07-13 10:00:00');
    $alternative = Carbon::parse('2026-07-13 11:00:00');
    $request = AppointmentRequest::factory()->linked()->create([
        'scheduled_at' => $primary,
        'alternative_scheduled_times' => [$alternative->toISOString()],
        'provisional_duration_minutes' => 30,
        'expires_at' => Carbon::parse('2026-07-14 17:00:00'),
    ]);

    Appointment::factory()->create([
        'scheduled_at' => $primary,
        'duration_minutes' => 30,
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee('No longer available')
        ->assertSee('Available')
        ->assertSee('Primary')
        ->assertSee('Alt 1');
});

test('request queue keeps unavailable preferred times visible without a summary', function () {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $primary = Carbon::parse('2026-07-13 10:00:00');
    $alternative = Carbon::parse('2026-07-13 11:00:00');
    $request = AppointmentRequest::factory()->linked()->create([
        'scheduled_at' => $primary,
        'alternative_scheduled_times' => [$alternative->toISOString()],
        'provisional_duration_minutes' => 30,
        'expires_at' => Carbon::parse('2026-07-14 17:00:00'),
    ]);

    Appointment::factory()->create([
        'scheduled_at' => $primary,
        'duration_minutes' => 30,
        'optometrist_id' => $optometrist->id,
    ]);
    Appointment::factory()->create([
        'scheduled_at' => $alternative,
        'duration_minutes' => 30,
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee('No longer available')
        ->assertDontSee('No submitted times available')
        ->assertDontSee('0 of 2 available');
});

test('resolved requests keep preferred times without live availability indicators', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->accepted()->create([
        'scheduled_at' => Carbon::parse('2026-07-13 10:00:00'),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee(Carbon::parse($request->getAllTimePreferences()[0])->format('M j, g:i A'))
        ->assertDontSee('Available')
        ->assertDontSee('No submitted times available');
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

test('expired pending requests appear in the resolved request tab', function () {
    $staff = User::factory()->staff()->create();
    $expired = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);
    $active = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ListAppointmentRequests::class);
    $resolvedQuery = AppointmentRequest::query();
    $component->instance()->getTabs()['resolved']->modifyQuery($resolvedQuery);

    expect($resolvedQuery->pluck('id')->all())
        ->toContain($expired->id)
        ->not->toContain($active->id);
});
