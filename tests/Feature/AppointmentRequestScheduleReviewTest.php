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

test('final appointment details use standard Filament input wrappers', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $html = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])->html();

    expect(substr_count($html, 'class="fi-input-wrp"'))->toBe(7)
        ->and($html)->toContain('fi-input')
        ->and($html)->toContain('fi-select-input');
});

test('review page clarifies clinic-wide availability before an optional provider is selected', function () {
    $staff = User::factory()->staff()->create();
    User::factory()->optometrist()->create();
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()]);
    $html = $component->html();

    expect($component->instance()->optometristId)->toBeNull()
        ->and($html)->toContain('Optometrist (optional)')
        ->and($html)->toContain('Availability is based on clinic capacity until a provider is selected.')
        ->and($html)->toContain('Clinic capacity available')
        ->and($html)->not->toContain('>Available</span>')
        ->and(strpos($html, 'Optometrist (optional)'))->toBeLessThan(strpos($html, 'Submitted preferences'));
});

test('selecting a preference or open calendar slot updates one scheduling state', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create();
    $primary = $request->scheduled_at->copy()->setTimezone(config('app.timezone'));
    $alternative = Carbon::parse($request->alternative_scheduled_times[0])->setTimezone(config('app.timezone'));
    $duration = $request->appointmentType->duration_minutes;
    $calendarSelection = Carbon::parse('2026-07-14T14:15:00+08:00')->setTimezone(config('app.timezone'));

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->assertSee('Selected slot')
        ->assertSee('From Primary preference')
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSee($primary->format('D, M j').' · '.$primary->format('g:i A').'–'.$primary->copy()->addMinutes($duration)->format('g:i A'))
        ->call('selectPreference', 1)
        ->assertSet('scheduledDate', $alternative->toDateString())
        ->assertSet('scheduledTime', $alternative->format('H:i'))
        ->assertSee('From Alternative 1')
        ->assertSee($alternative->format('D, M j').' · '.$alternative->format('g:i A').'–'.$alternative->copy()->addMinutes($duration)->format('g:i A'))
        ->call('selectCalendarSlot', $calendarSelection->toIso8601String())
        ->assertSet('scheduledDate', $calendarSelection->toDateString())
        ->assertSet('scheduledTime', $calendarSelection->format('H:i'))
        ->assertSee('From Custom time');

    $manual = Carbon::parse('2026-07-15 11:45', config('app.timezone'));

    $component
        ->set('scheduledDate', $manual->toDateString())
        ->set('scheduledTime', $manual->format('H:i'))
        ->assertSee('From Custom time')
        ->assertSee($manual->format('D, M j').' · '.$manual->format('g:i A').'–'.$manual->copy()->addMinutes($duration)->format('g:i A'));

    $component
        ->set('scheduledDate', '')
        ->assertSee('Choose a valid date and time')
        ->assertSee('Not selected');
});
