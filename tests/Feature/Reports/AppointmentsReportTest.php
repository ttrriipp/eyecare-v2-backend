<?php

use App\Filament\Clusters\Reports\Pages\AppointmentsReport;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('appointments report uses scheduled date cohorts and current terminal outcomes', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $comprehensive = AppointmentType::factory()->create(['name' => 'Comprehensive Exam']);
    $contactLens = AppointmentType::factory()->create(['name' => 'Contact Lens Review']);

    Appointment::factory()->create([
        'appointment_type_id' => $comprehensive->id,
        'source' => 'manual',
        'scheduled_at' => '2026-08-01 09:00:00',
    ]);
    Appointment::factory()->checkedIn()->create([
        'appointment_type_id' => $contactLens->id,
        'source' => 'mobile',
        'scheduled_at' => '2026-08-10 09:00:00',
    ]);
    Appointment::factory()->fulfilled()->create([
        'appointment_type_id' => $comprehensive->id,
        'source' => 'manual',
        'scheduled_at' => '2026-08-15 09:00:00',
    ]);
    Appointment::factory()->cancelled()->create([
        'appointment_type_id' => $comprehensive->id,
        'source' => 'mobile',
        'scheduled_at' => '2026-08-20 09:00:00',
    ]);
    Appointment::factory()->noShow()->create([
        'appointment_type_id' => $contactLens->id,
        'source' => 'manual',
        'scheduled_at' => '2026-08-30 23:59:59',
    ]);
    Appointment::factory()->fulfilled()->create([
        'source' => 'manual',
        'scheduled_at' => '2026-08-31 00:00:00',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(AppointmentsReport::class);
    $stats = collect($component->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Appointments')?->getValue())
        ->toBe('5')
        ->and($stats->get('Terminal outcomes')?->getValue())
        ->toBe('3')
        ->and($stats->get('Fulfillment rate')?->getValue())
        ->toBe('33%')
        ->and($stats->get('No-show rate')?->getValue())
        ->toBe('33%');

    $sections = collect($component->instance()->getSections())->keyBy('title');
    $outcomes = collect($sections->get('Current outcome')['rows'])->keyBy('label');
    $sources = collect($sections->get('Appointment source')['rows'])->keyBy('label');
    $types = collect($sections->get('Appointment type')['rows'])->keyBy('label');

    expect($outcomes->get('Scheduled')['value'])
        ->toBe(1)
        ->and($outcomes->get('Checked In')['value'])
        ->toBe(1)
        ->and($outcomes->get('Fulfilled')['value'])
        ->toBe(1)
        ->and($outcomes->get('Cancelled')['value'])
        ->toBe(1)
        ->and($outcomes->get('No-show')['value'])
        ->toBe(1)
        ->and($sources->get('Manual')['value'])
        ->toBe(3)
        ->and($sources->get('Mobile')['value'])
        ->toBe(2)
        ->and($types->get('Comprehensive Exam')['value'])
        ->toBe(3)
        ->and($types->get('Contact Lens Review')['value'])
        ->toBe(2);
});

test('appointments report keeps a zero terminal denominator explicit', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    Appointment::factory()->count(2)->create([
        'scheduled_at' => '2026-08-30 09:00:00',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $stats = collect(Livewire::test(AppointmentsReport::class)->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Terminal outcomes')?->getValue())
        ->toBe('0')
        ->and($stats->get('Fulfillment rate')?->getValue())
        ->toBe('0%')
        ->and($stats->get('No-show rate')?->getValue())
        ->toBe('0%');
});
