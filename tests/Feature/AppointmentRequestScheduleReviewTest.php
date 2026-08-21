<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Models\AppointmentRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('linked pending requests can open the review and schedule page', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
    ]);

    $this->actingAs($staff);

    expect(AppointmentRequestResource::getPages())->toHaveKey('schedule');

    $this->get(AppointmentRequestResource::getUrl('schedule', ['record' => $request]))
        ->assertSuccessful()
        ->assertSee('Review & Schedule');
});

test('the request detail page points linked pending work to review and schedule', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('reviewSchedule')
        ->assertActionDoesNotExist('accept');
});

test('unlinked, expired, and terminal requests cannot open schedule review', function () {
    $staff = User::factory()->staff()->create();
    $unlinked = AppointmentRequest::factory()->create(['patient_id' => null]);
    $expired = AppointmentRequest::factory()->linked()->expired()->create();
    $accepted = AppointmentRequest::factory()->linked()->accepted()->create();

    $this->actingAs($staff);

    foreach ([$unlinked, $expired, $accepted] as $request) {
        $this->get(AppointmentRequestResource::getUrl('schedule', ['record' => $request]))
            ->assertForbidden();
    }
});

test('patients cannot access schedule review', function () {
    $patient = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($patient);

    $this->get(AppointmentRequestResource::getUrl('schedule', ['record' => $request]))
        ->assertForbidden();
});

test('review page initializes the submitted primary preference', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create();

    $this->actingAs($staff);

    Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->assertSet('scheduledDate', $request->scheduled_at->toDateString())
        ->assertSet('scheduledTime', $request->scheduled_at->format('H:i'))
        ->assertSee('Primary preference');
});

test('selecting a preference or open calendar slot updates one scheduling state', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create();

    $this->actingAs($staff);

    Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->call('selectPreference', 1)
        ->assertSet('scheduledDate', $request->alternative_scheduled_times[0] ? Carbon::parse($request->alternative_scheduled_times[0])->toDateString() : null)
        ->assertSet('scheduledTime', $request->alternative_scheduled_times[0] ? Carbon::parse($request->alternative_scheduled_times[0])->format('H:i') : null)
        ->call('selectCalendarSlot', '2026-07-14T14:15:00+08:00')
        ->assertSet('scheduledDate', '2026-07-14')
        ->assertSet('scheduledTime', '14:15');
});
