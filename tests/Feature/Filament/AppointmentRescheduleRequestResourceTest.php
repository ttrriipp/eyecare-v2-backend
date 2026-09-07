<?php

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Filament\Resources\AppointmentRescheduleRequests\AppointmentRescheduleRequestResource;
use App\Filament\Resources\AppointmentRescheduleRequests\Pages\ListAppointmentRescheduleRequests;
use App\Filament\Resources\AppointmentRescheduleRequests\Pages\ViewAppointmentRescheduleRequest;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\AppointmentStatus;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-07 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeQueueRequest(array $attributes = []): AppointmentRescheduleRequest
{
    $account = User::factory()->patient()->create();
    $scheduledAt = Carbon::parse('2026-09-10 10:00:00');
    $appointment = Appointment::factory()->create([
        'patient_id' => $account->patient->id,
        'scheduled_at' => $scheduledAt,
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Scheduled->value)
            ->value('id'),
    ]);

    return AppointmentRescheduleRequest::factory()->create(array_merge([
        'appointment_id' => $appointment->id,
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'current_scheduled_at' => $scheduledAt,
        'requested_scheduled_at' => '2026-09-11 10:00:00',
        'expires_at' => '2026-09-09 10:00:00',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeReviewableQueueRequest(array $attributes = []): AppointmentRescheduleRequest
{
    $request = makeQueueRequest($attributes);
    $optometrist = User::factory()->optometrist()->create();

    $request->appointment->update([
        'optometrist_id' => $optometrist->id,
    ]);

    return $request->fresh([
        'appointment.optometrist',
        'appointment.status',
        'patient',
    ]);
}

test('resource is read-only and exposes the review queue model', function (): void {
    expect(AppointmentRescheduleRequestResource::getModel())
        ->toBe(AppointmentRescheduleRequest::class)
        ->and(AppointmentRescheduleRequestResource::canCreate())->toBeFalse();
});

test('active panel users can access the reschedule request queue', function (string $factoryState): void {
    $user = User::factory()->{$factoryState}()->create();

    $this->actingAs($user)
        ->get(AppointmentRescheduleRequestResource::getUrl('index'))
        ->assertSuccessful();
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
    'optometrist' => ['optometrist'],
]);

test('inactive and patient-only accounts cannot access the queue', function (): void {
    $inactiveStaff = User::factory()->staff()->create(['is_active' => false]);
    $this->actingAs($inactiveStaff)
        ->get(AppointmentRescheduleRequestResource::getUrl('index'))
        ->assertForbidden();

    $patient = User::factory()->patient()->create();
    $this->actingAs($patient)
        ->get(AppointmentRescheduleRequestResource::getUrl('index'))
        ->assertForbidden();
});

test('navigation badge counts only effective pending requests', function (): void {
    makeQueueRequest();
    $expired = makeQueueRequest(['expires_at' => now()->subMinute()]);
    $terminal = makeQueueRequest();
    $terminal->appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->value('id'),
    ]);
    makeQueueRequest(['status' => AppointmentRescheduleRequestStatus::Rejected]);

    expect(AppointmentRescheduleRequestResource::getNavigationBadge())->toBe('1')
        ->and($expired->fresh()->effectiveStatus())->toBe(AppointmentRescheduleRequestStatus::Expired)
        ->and($terminal->fresh()->effectiveStatus())->toBe(AppointmentRescheduleRequestStatus::Expired);
});

test('queue shows operational fields and keeps patient free-text reasons out of the table', function (): void {
    $staff = User::factory()->staff()->create();
    $request = makeQueueRequest([
        'encrypted_reason_details' => 'Sensitive patient explanation that must stay private.',
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRescheduleRequests::class)
        ->assertCanSeeTableRecords([$request])
        ->assertTableColumnExists('request_number')
        ->assertTableColumnExists('appointment.appointment_number')
        ->assertTableColumnExists('patient.full_name')
        ->assertTableColumnExists('current_scheduled_at')
        ->assertTableColumnExists('requested_scheduled_at')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('status')
        ->assertTableColumnDoesNotExist('encrypted_reason_details')
        ->assertSee($request->request_number)
        ->assertDontSee('Sensitive patient explanation');
});

test('effective pending requests sort before terminal and expired history', function (): void {
    $staff = User::factory()->staff()->create();
    $pending = makeQueueRequest(['created_at' => now()->subDays(2)]);
    $expired = makeQueueRequest([
        'expires_at' => now()->subMinute(),
        'created_at' => now()->subDay(),
    ]);
    $rejected = makeQueueRequest([
        'status' => AppointmentRescheduleRequestStatus::Rejected,
        'created_at' => now(),
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRescheduleRequests::class)
        ->assertCanSeeTableRecords([$pending, $expired, $rejected], inOrder: true)
        ->assertTableColumnFormattedStateSet('status', 'Pending', record: $pending)
        ->assertTableColumnFormattedStateSet('status', 'Expired', record: $expired)
        ->assertTableColumnFormattedStateSet('status', 'Rejected', record: $rejected);
});

test('queue search and effective status filters work', function (): void {
    $staff = User::factory()->staff()->create();
    $pending = makeQueueRequest();
    $expired = makeQueueRequest(['expires_at' => now()->subMinute()]);

    $this->actingAs($staff);

    Livewire::test(ListAppointmentRescheduleRequests::class)
        ->searchTable($pending->request_number)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$expired])
        ->searchTable(null)
        ->filterTable('status', AppointmentRescheduleRequestStatus::Pending->value)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$expired])
        ->filterTable('status', AppointmentRescheduleRequestStatus::Expired->value)
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$pending]);
});

test('queue exposes pending and history tabs using effective state', function (): void {
    $staff = User::factory()->staff()->create();
    $pending = makeQueueRequest();
    $expired = makeQueueRequest(['expires_at' => now()->subMinute()]);

    $this->actingAs($staff);

    $component = Livewire::test(ListAppointmentRescheduleRequests::class);
    $tabs = $component->instance()->getTabs();
    $pendingQuery = AppointmentRescheduleRequest::query();
    $tabs['pending']->modifyQuery($pendingQuery);
    $historyQuery = AppointmentRescheduleRequest::query();
    $tabs['history']->modifyQuery($historyQuery);

    expect($pendingQuery->pluck('id')->all())->toBe([$pending->id])
        ->and($historyQuery->pluck('id')->all())->toBe([$expired->id]);
});

test('review page displays staff-safe context and current availability without patient reason text', function (): void {
    $staff = User::factory()->staff()->create();
    $request = makeReviewableQueueRequest([
        'encrypted_reason_details' => 'Private explanation that must not be shown to staff in this workflow.',
        'alternative_scheduled_times' => ['2026-09-11T11:00:00+08:00'],
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($request->request_number)
        ->assertSee($request->patient->full_name)
        ->assertSee('Original appointment')
        ->assertSee('Submitted choices')
        ->assertSee('Available')
        ->assertDontSee('Private explanation that must not be shown');
});

test('staff can approve one currently available submitted choice from the review page', function (): void {
    $staff = User::factory()->staff()->create();
    $request = makeReviewableQueueRequest();
    $appointment = $request->appointment->fresh();
    $selected = $request->requested_scheduled_at->toIso8601String();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->callAction('approve', ['selected_scheduled_at' => $selected])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Approved)
        ->and($request->fresh()->selected_scheduled_at?->equalTo($request->requested_scheduled_at))->toBeTrue()
        ->and($appointment->fresh()->scheduled_at->equalTo($request->requested_scheduled_at))->toBeTrue()
        ->and($appointment->reschedules()->count())->toBe(1);
});

test('staff can reject a pending request with a patient-safe reason', function (): void {
    $staff = User::factory()->staff()->create();
    $request = makeReviewableQueueRequest();
    $scheduledAt = $request->appointment->scheduled_at->toDateTimeString();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('reject')
        ->callAction('reject', ['rejection_reason' => 'That time is no longer available.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status)->toBe(AppointmentRescheduleRequestStatus::Rejected)
        ->and($request->fresh()->rejection_reason)->toBe('That time is no longer available.')
        ->and($request->appointment->fresh()->scheduled_at->toDateTimeString())->toBe($scheduledAt)
        ->and($request->appointment->reschedules()->count())->toBe(0);
});

test('terminal and expired requests expose no review mutation actions', function (): void {
    $staff = User::factory()->staff()->create();
    $expired = makeReviewableQueueRequest(['expires_at' => now()->subMinute()]);
    $terminal = makeReviewableQueueRequest();
    $terminal->appointment->update([
        'appointment_status_id' => AppointmentStatus::query()
            ->where('name', AppointmentStatusName::Cancelled->value)
            ->value('id'),
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $expired->getRouteKey()])
        ->assertSee('Expired')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $terminal->getRouteKey()])
        ->assertSee('Expired')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

test('concurrent resolution leaves no review action or appointment movement', function (): void {
    $staff = User::factory()->staff()->create();
    $request = makeReviewableQueueRequest();
    $appointment = $request->appointment->fresh();
    $appointmentTime = $appointment->scheduled_at->toDateTimeString();

    $this->actingAs($staff);

    $request->update(['status' => AppointmentRescheduleRequestStatus::Rejected]);

    Livewire::test(ViewAppointmentRescheduleRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Rejected')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');

    expect($appointment->fresh()->scheduled_at->toDateTimeString())->toBe($appointmentTime)
        ->and($appointment->reschedules()->count())->toBe(0);
});
