<?php

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Filament\Resources\AppointmentRequests\Widgets\AppointmentRequestScheduleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Guava\Calendar\ValueObjects\FetchInfo;
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

test('submitted preferences show a readable label, submitted time, and concise availability', function () {
    $staff = User::factory()->staff()->create();
    User::factory()->optometrist()->create();
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    $html = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])->html();

    expect($html)
        ->toContain('data-test="preference-label"')
        ->and($html)->toContain('Primary preference')
        ->and($html)->toContain('data-test="preference-time"')
        ->and($html)->toContain($scheduledAt->format('D, M j · g:i A'))
        ->and($html)->toContain('data-test="preference-status"')
        ->and($html)->toContain('>Available</span>');
});

test('scheduling fields use standard Filament input wrappers', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $html = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])->html();

    expect(substr_count($html, 'class="fi-input-wrp"'))->toBe(7)
        ->and($html)->toContain('fi-input')
        ->and($html)->toContain('fi-select-input');
});

test('review page keeps scheduling section headers focused', function () {
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
        ->and($html)->toContain('data-test="slot-availability"')
        ->and($html)->toContain('data-test="preference-status"')
        ->and($html)->toContain('>Available</span>')
        ->and($html)->not->toContain('Click to use this time')
        ->and($html)->not->toContain('Availability is based on clinic capacity until a provider is selected.')
        ->and(strpos($html, 'Optometrist (optional)'))->toBeLessThan(strpos($html, 'Submitted preferences'));
});

test('review page keeps the acceptance action in the header without a duplicate decision section', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()]);
    $html = $component->html();

    $component
        ->assertActionVisible('accept')
        ->assertActionHasLabel('accept', 'Accept & Schedule');

    expect($html)->not->toContain('data-test="schedule-decision"')
        ->and($html)->not->toContain('Schedule decision')
        ->and($html)->toContain('Scheduling details')
        ->and($html)->toContain('Submitted preferences');
});

test('review page keeps final conflict validation on the acceptance action', function () {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => $scheduledAt,
        'duration_minutes' => 30,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->set('durationMinutes', 30)
        ->set('optometristId', $optometrist->id)
        ->set('scheduledDate', $scheduledAt->toDateString())
        ->set('scheduledTime', $scheduledAt->format('H:i'));

    $component
        ->assertActionVisible('accept')
        ->callAction('accept')
        ->assertHasErrors(['scheduledDate']);

    $html = $component->html();

    expect($html)->not->toContain('Selected slot availability')
        ->and($html)->not->toContain('Schedule decision');
});

test('schedule context uses a compact day calendar', function () {
    $request = AppointmentRequest::factory()->linked()->create();
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);

    $options = Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
    ])->instance()->getOptions();

    expect($options['height'])->toBe(480)
        ->and($options['slotHeight'])->toBe(28)
        ->and($options['slotLabelInterval'])->toBe('01:00:00')
        ->and($options['date'])->toBe($scheduledAt->toIso8601String())
        ->and($options)->not->toHaveKey('initialDate');
});

test('review page shows remaining clinic capacity for an unassigned slot', function () {
    $staff = User::factory()->staff()->create();
    $firstOptometrist = User::factory()->optometrist()->create();
    User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    Appointment::factory()->create([
        'optometrist_id' => $firstOptometrist->id,
        'scheduled_at' => $scheduledAt,
        'duration_minutes' => 30,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()]);

    expect($component->html())->toContain('1 of 2 clinic slots available')
        ->and($component->instance()->selectedSlotStatus())->toMatchArray([
            'state' => 'available',
            'label' => '1 of 2 clinic slots available',
        ]);
});

test('review page marks a capacity-blocked slot unavailable', function () {
    $staff = User::factory()->staff()->create();
    $firstOptometrist = User::factory()->optometrist()->create();
    $secondOptometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    foreach ([$firstOptometrist, $secondOptometrist] as $optometrist) {
        Appointment::factory()->create([
            'optometrist_id' => $optometrist->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ]);
    }

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()]);

    expect($component->html())->toContain('Unavailable — capacity reached')
        ->and($component->instance()->selectedSlotStatus())->toMatchArray([
            'state' => 'unavailable',
            'label' => 'Unavailable — capacity reached',
        ]);

    $widget = Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
        'proposedSlotAvailable' => false,
    ])->instance();
    $method = new ReflectionMethod($widget, 'getEvents');
    $method->setAccessible(true);

    $events = $method->invoke($widget, new FetchInfo([
        'startStr' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'endStr' => $scheduledAt->copy()->endOfDay()->toIso8601String(),
    ]));
    $preview = collect($events)->last();

    expect($preview->getBackgroundColor())->toBe('#dc2626')
        ->and($preview->getTitle())->toBe('Unavailable slot')
        ->and($preview->getClassNames())->toContain('ec-preview-unavailable');
});

test('schedule context mutes appointments outside the selected provider', function () {
    $selectedProvider = User::factory()->optometrist()->create();
    $otherProvider = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create();
    $request = AppointmentRequest::factory()->linked()->create();
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);

    Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'optometrist_id' => $otherProvider->id,
        'scheduled_at' => $scheduledAt,
    ]);

    $widget = Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'optometristId' => $selectedProvider->id,
    ])->instance();
    $method = new ReflectionMethod($widget, 'getEvents');
    $method->setAccessible(true);

    $events = $method->invoke($widget, new FetchInfo([
        'startStr' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'endStr' => $scheduledAt->copy()->endOfDay()->toIso8601String(),
    ]));
    $event = $events[0];

    expect($event->getBackgroundColor())->toBe('#94a3b8')
        ->and($event->getClassNames())->toContain('ec-context-appointment');
});

test('review page omits section helper descriptions', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $html = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])->html();

    expect($html)->not->toContain('Set the appointment type and duration, then optionally assign a provider before reviewing availability.')
        ->and($html)->not->toContain('Availability is checked against the selected provider.')
        ->and($html)->not->toContain('Active appointments are shown as clinic capacity in use. Click an open time to use it.')
        ->and($html)->not->toContain('Schedule context')
        ->and($html)->toContain('Calendar');
});

test('selecting a preference or open calendar slot updates one scheduling state', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->withAlternatives()->create();
    $alternative = Carbon::parse($request->alternative_scheduled_times[0])->setTimezone(config('app.timezone'));
    $calendarSelection = Carbon::parse('2026-07-14T14:15:00+08:00')->setTimezone(config('app.timezone'));

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->assertSeeHtml('aria-pressed="true"')
        ->call('selectPreference', 1)
        ->assertSet('scheduledDate', $alternative->toDateString())
        ->assertSet('scheduledTime', $alternative->format('H:i'))
        ->call('selectCalendarSlot', $calendarSelection->toIso8601String())
        ->assertSet('scheduledDate', $calendarSelection->toDateString())
        ->assertSet('scheduledTime', $calendarSelection->format('H:i'));

    $manual = Carbon::parse('2026-07-15 11:45', config('app.timezone'));

    $component
        ->set('scheduledDate', $manual->toDateString())
        ->set('scheduledTime', $manual->format('H:i'))
        ->assertSet('scheduledDate', $manual->toDateString())
        ->assertSet('scheduledTime', $manual->format('H:i'));

    $component
        ->set('scheduledDate', '')
        ->assertSet('scheduledDate', '')
        ->assertSet('scheduledTime', $manual->format('H:i'));

    expect($component->instance()->selectedDateTime())->toBeNull();
});
