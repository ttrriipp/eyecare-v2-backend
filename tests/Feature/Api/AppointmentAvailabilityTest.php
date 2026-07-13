<?php

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(AppointmentStatusSeeder::class);
    $this->customer = User::factory()->customer()->create();
    $this->visitReason = VisitReason::factory()->create(['duration_minutes' => 30]);
    $this->optometrist = User::factory()->optometrist()->create();
});

afterEach(fn () => Carbon::setTestNow());

test('availability returns the appointment slot grid contract with clinic metadata', function () {
    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'date',
            'timezone',
            'interval_minutes',
            'visit_reason_id',
            'visit_duration_minutes',
            'optometrist_id',
            'appointment_id',
            'day_status',
            'generated_at',
            'slots' => [
                '*' => [
                    'starts_at',
                    'ends_at',
                    'available',
                    'reason',
                ],
            ],
        ],
    ]);

    $response->assertJsonPath('data.date', '2026-07-13')
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.interval_minutes', 15)
        ->assertJsonPath('data.visit_reason_id', $this->visitReason->id)
        ->assertJsonPath('data.visit_duration_minutes', 30)
        ->assertJsonPath('data.optometrist_id', $this->optometrist->id)
        ->assertJsonPath('data.appointment_id', null)
        ->assertJsonPath('data.day_status', 'open');

    $slots = collect($response->json('data.slots'));

    expect($slots)->toHaveCount(31)
        ->and($slots->first())->toMatchArray([
            'starts_at' => '2026-07-13T09:00:00+08:00',
            'ends_at' => '2026-07-13T09:30:00+08:00',
            'available' => true,
            'reason' => null,
        ])
        ->and($slots->last())->toMatchArray([
            'starts_at' => '2026-07-13T16:30:00+08:00',
            'ends_at' => '2026-07-13T17:00:00+08:00',
            'available' => true,
            'reason' => null,
        ])
        ->and($response->json('data.generated_at'))->toEndWith('+08:00');
});

test('availability marks overlapping slots unavailable for the selected optometrist', function () {
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('09:45'))->toMatchArray([
        'available' => false,
        'reason' => 'capacity_reached',
    ])
        ->and($slotsByTime->get('10:00'))->toMatchArray([
            'available' => false,
            'reason' => 'capacity_reached',
        ])
        ->and($slotsByTime->get('10:15'))->toMatchArray([
            'available' => false,
            'reason' => 'capacity_reached',
        ])
        ->and($slotsByTime->get('10:30'))->toMatchArray([
            'available' => true,
            'reason' => null,
        ]);
});

test('availability without an optometrist uses clinic provider capacity', function () {
    User::factory()->optometrist()->create();
    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
    ]));

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('10:00.available'))->toBeTrue();
});

test('availability requires authentication and valid query input', function () {
    $this->getJson('/api/appointments/availability')->assertUnauthorized();

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/appointments/availability')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date', 'visit_reason_id']);
});

test('availability returns a closed day state for Sundays', function () {
    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-12',
        'visit_reason_id' => $this->visitReason->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('data.date', '2026-07-12')
        ->assertJsonPath('data.day_status', 'closed')
        ->assertJsonPath('data.slots', []);
});

test('availability keeps same-day elapsed slots in the grid as unavailable', function () {
    Carbon::setTestNow('2026-07-13 10:07:00');

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
    ]));

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('09:00'))->toMatchArray([
        'available' => false,
        'reason' => 'elapsed',
    ])
        ->and($slotsByTime->get('10:00'))->toMatchArray([
            'available' => false,
            'reason' => 'elapsed',
        ])
        ->and($slotsByTime->get('10:15'))->toMatchArray([
            'available' => true,
            'reason' => null,
        ]);
});

test('availability detects overlaps when durations are not multiples of the slot interval', function () {
    $twentyMinuteReason = VisitReason::factory()->create(['duration_minutes' => 20]);
    $fifteenMinuteReason = VisitReason::factory()->create(['duration_minutes' => 15]);

    Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $twentyMinuteReason->id,
        'scheduled_at' => '2026-07-13 11:30:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $fifteenMinuteReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('11:45'))->toMatchArray([
        'available' => false,
        'reason' => 'capacity_reached',
    ])
        ->and($slotsByTime->get('12:00'))->toMatchArray([
            'available' => true,
            'reason' => null,
        ]);
});

test('availability excludes cancelled no show and soft deleted appointments from capacity', function (string $statusName) {
    $status = AppointmentStatus::query()->where('name', $statusName)->firstOrFail();

    $appointment = Appointment::factory()->create([
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'appointment_status_id' => $status->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    if ($statusName === 'pending') {
        $appointment->delete();
    }

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
    ]));

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('10:00'))->toMatchArray([
        'available' => true,
        'reason' => null,
    ]);
})->with([
    'cancelled',
    'no_show',
    'pending',
]);

test('availability validates optometrist eligibility instead of returning an empty success', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/appointments/availability?'.http_build_query([
            'date' => '2026-07-13',
            'visit_reason_id' => $this->visitReason->id,
            'optometrist_id' => $staff->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['optometrist_id']);
});

test('availability can exclude the appointment being rescheduled', function () {
    $appointment = Appointment::factory()->create([
        'customer_id' => $this->customer->id,
        'optometrist_id' => $this->optometrist->id,
        'visit_reason_id' => $this->visitReason->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')->getJson('/api/appointments/availability?'.http_build_query([
        'date' => '2026-07-13',
        'visit_reason_id' => $this->visitReason->id,
        'optometrist_id' => $this->optometrist->id,
        'appointment_id' => $appointment->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('data.appointment_id', $appointment->id);

    $slotsByTime = collect($response->json('data.slots'))->keyBy(
        fn (array $slot): string => Carbon::parse($slot['starts_at'])->format('H:i'),
    );

    expect($slotsByTime->get('10:00'))->toMatchArray([
        'available' => true,
        'reason' => null,
    ]);
});
