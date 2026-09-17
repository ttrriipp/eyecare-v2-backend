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
use Database\Seeders\NotificationStatusSeeder;
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

test('referring source input only appears for appointment types that require referral', function (): void {
    $staff = User::factory()->staff()->create();
    $nonReferralType = AppointmentType::factory()->create([
        'requires_referral' => false,
    ]);
    $referralType = AppointmentType::factory()->referral()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $nonReferralType->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->assertDontSee('Referring source')
        ->set('appointmentTypeId', $referralType->id)
        ->assertSee('Referring source');
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

    expect(substr_count($html, 'class="fi-input-wrp"'))->toBe(6)
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
        ->and($html)->toContain('Optometrist</span>')
        ->and($html)->not->toContain('(optional)')
        ->and(substr_count($html, 'class="fi-fo-field-label-required-mark"'))->toBe(4)
        ->and($html)->toContain('data-test="slot-availability"')
        ->and($html)->toContain('data-test="preference-status"')
        ->and($html)->toContain('>Available</span>')
        ->and($html)->not->toContain('Click to use this time')
        ->and(strpos($html, 'Optometrist</span>'))->toBeLessThan(strpos($html, 'Submitted preferences'));
});

test('review page keeps the acceptance action in the header without a duplicate decision section', function () {
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
        ->assertActionHidden('accept')
        ->call('accept')
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

test('review page shows simple availability for an unassigned slot', function () {
    $staff = User::factory()->staff()->create();
    User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create(['duration_minutes' => 30]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $appointmentType->id,
        'scheduled_at' => $scheduledAt,
        'expires_at' => $scheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()]);

    expect($component->html())->toContain('Time available')
        ->and($component->instance()->selectedSlotStatus())->toMatchArray([
            'state' => 'available',
            'label' => 'Time available',
        ]);
});

test('review page marks an occupied unassigned slot unavailable', function () {
    $staff = User::factory()->staff()->create();
    $firstOptometrist = User::factory()->optometrist()->create();
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

    $component->assertActionHidden('accept');

    expect($component->html())->toContain('Unavailable — time unavailable')
        ->and($component->instance()->selectedSlotStatus())->toMatchArray([
            'state' => 'unavailable',
            'label' => 'Unavailable — time unavailable',
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

test('only the proposed slot is draggable and its drop updates the selected schedule', function () {
    $requestType = AppointmentType::factory()->create();
    $optometrist = User::factory()->optometrist()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $requestType->id,
    ]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $appointment = Appointment::factory()->create([
        'scheduled_at' => $scheduledAt->copy()->addHour(),
    ]);

    $widget = Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'appointmentTypeId' => $requestType->id,
        'durationMinutes' => 30,
        'optometristId' => $optometrist->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
        'proposedSlotAvailable' => true,
    ])->instance();
    $method = new ReflectionMethod($widget, 'getEvents');
    $method->setAccessible(true);
    $events = $method->invoke($widget, new FetchInfo([
        'startStr' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'endStr' => $scheduledAt->copy()->endOfDay()->toIso8601String(),
    ]));
    $preview = collect($events)->first(fn ($event): bool => $event->getTitle() === 'Proposed slot');
    $appointmentEvent = collect($events)->first(fn ($event): bool => $event->getExtendedProps()['key'] === (string) $appointment->id);

    expect($widget->isEventDragEnabled())->toBeTrue()
        ->and($preview->getExtendedProps())->toMatchArray([
            'model' => AppointmentRequest::class,
            'key' => (string) $request->id,
            'kind' => 'proposed_slot',
        ])
        ->and($preview->getEditable())->toBeTrue()
        ->and($preview->getDurationEditable())->toBeFalse()
        ->and($appointmentEvent->getEditable())->toBeFalse();

    $droppedAt = $scheduledAt->copy()->addDay()->setTime(11, 15);
    $event = [
        'title' => 'Proposed slot',
        'start' => $droppedAt->toIso8601String(),
        'end' => $droppedAt->copy()->addMinutes(30)->toIso8601String(),
        'allDay' => false,
        'styles' => ['cursor: grab'],
        'classNames' => ['ec-preview'],
        'extendedProps' => [
            'model' => AppointmentRequest::class,
            'key' => (string) $request->id,
            'kind' => 'proposed_slot',
        ],
        'display' => 'auto',
        'resourceIds' => [],
    ];
    $view = [
        'type' => 'timeGridDay',
        'title' => 'Monday',
        'currentStart' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'currentEnd' => $scheduledAt->copy()->addDay()->startOfDay()->toIso8601String(),
        'activeStart' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'activeEnd' => $scheduledAt->copy()->addDay()->startOfDay()->toIso8601String(),
    ];

    Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'appointmentTypeId' => $requestType->id,
        'durationMinutes' => 30,
        'optometristId' => $optometrist->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
        'proposedSlotAvailable' => true,
    ])
        ->call('onEventDropJs', [
            'event' => $event,
            'oldEvent' => array_merge($event, [
                'start' => $scheduledAt->toIso8601String(),
                'end' => $scheduledAt->copy()->addMinutes(30)->toIso8601String(),
            ]),
            'oldResource' => null,
            'newResource' => null,
            'delta' => [],
            'view' => $view,
            'tzOffset' => 480,
        ])
        ->assertReturned(true)
        ->assertDispatchedTo(
            ReviewAppointmentRequestSchedule::class,
            'appointment-request-schedule-slot-selected',
            fn (string $name, array $parameters): bool => Carbon::parse($parameters['start'])->equalTo($droppedAt),
        );

    Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'appointmentTypeId' => $requestType->id,
        'durationMinutes' => 7,
        'optometristId' => $optometrist->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
        'proposedSlotAvailable' => true,
    ])
        ->call('onEventDropJs', [
            'event' => $event,
            'oldEvent' => array_merge($event, [
                'start' => $scheduledAt->toIso8601String(),
                'end' => $scheduledAt->copy()->addMinutes(30)->toIso8601String(),
            ]),
            'oldResource' => null,
            'newResource' => null,
            'delta' => [],
            'view' => $view,
            'tzOffset' => 480,
        ])
        ->assertReturned(false)
        ->assertNotDispatched('appointment-request-schedule-slot-selected');
});

test('dragging the proposed slot to an unavailable time reverts without changing the schedule', function () {
    $requestType = AppointmentType::factory()->create();
    $optometrist = User::factory()->optometrist()->create();
    $request = AppointmentRequest::factory()->linked()->create([
        'appointment_type_id' => $requestType->id,
    ]);
    $scheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $droppedAt = $scheduledAt->copy()->setTime(18, 0);
    $event = [
        'title' => 'Proposed slot',
        'start' => $droppedAt->toIso8601String(),
        'end' => $droppedAt->copy()->addMinutes(30)->toIso8601String(),
        'allDay' => false,
        'styles' => ['cursor: grab'],
        'classNames' => ['ec-preview'],
        'extendedProps' => [
            'model' => AppointmentRequest::class,
            'key' => (string) $request->id,
            'kind' => 'proposed_slot',
        ],
        'display' => 'auto',
        'resourceIds' => [],
    ];
    $view = [
        'type' => 'timeGridDay',
        'title' => 'Monday',
        'currentStart' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'currentEnd' => $scheduledAt->copy()->addDay()->startOfDay()->toIso8601String(),
        'activeStart' => $scheduledAt->copy()->startOfDay()->toIso8601String(),
        'activeEnd' => $scheduledAt->copy()->addDay()->startOfDay()->toIso8601String(),
    ];

    Livewire::test(AppointmentRequestScheduleCalendar::class, [
        'requestId' => $request->id,
        'appointmentTypeId' => $requestType->id,
        'durationMinutes' => 30,
        'optometristId' => $optometrist->id,
        'proposedStart' => $scheduledAt->toIso8601String(),
        'proposedSlotAvailable' => true,
    ])
        ->call('onEventDropJs', [
            'event' => $event,
            'oldEvent' => array_merge($event, [
                'start' => $scheduledAt->toIso8601String(),
                'end' => $scheduledAt->copy()->addMinutes(30)->toIso8601String(),
            ]),
            'oldResource' => null,
            'newResource' => null,
            'delta' => [],
            'view' => $view,
            'tzOffset' => 480,
        ])
        ->assertReturned(false)
        ->assertNotDispatched('appointment-request-schedule-slot-selected');
});

test('review page omits section helper descriptions', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $html = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])->html();

    expect($html)->not->toContain('Set the appointment type and duration, then optionally assign a provider before reviewing availability.')
        ->and($html)->not->toContain('Availability is checked against the selected provider.')
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

test('ordinary requests reject durations that are not in five-minute increments', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->set('durationMinutes', 7);

    expect($component->instance()->selectedSlotStatus())->toMatchArray([
        'state' => 'incomplete',
        'label' => 'Use 5-minute increments for duration',
    ]);

    $component
        ->call('accept')
        ->assertHasErrors(['durationMinutes' => 'multiple_of']);
});

test('rebooking keeps the appointment duration even when it is not a five-minute increment', function () {
    $this->seed(NotificationStatusSeeder::class);

    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointmentType = AppointmentType::factory()->create();
    $originalScheduledAt = now()->next(Carbon::MONDAY)->setTime(10, 0);
    $newScheduledAt = $originalScheduledAt->copy()->addDay();
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
        'duration_minutes' => 7,
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => $originalScheduledAt,
    ]);
    $request = AppointmentRequest::factory()->rebookingFor($appointment)->create([
        'scheduled_at' => $newScheduledAt,
        'expires_at' => $newScheduledAt->copy()->addDay(),
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(ReviewAppointmentRequestSchedule::class, ['record' => $request->getRouteKey()])
        ->assertSet('durationMinutes', 7);

    expect($component->instance()->selectedSlotStatus()['state'])->toBe('available');

    $component
        ->call('accept')
        ->assertHasNoErrors();

    expect($appointment->fresh()->scheduled_at->equalTo($newScheduledAt))->toBeTrue();
});
