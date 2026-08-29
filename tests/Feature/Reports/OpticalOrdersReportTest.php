<?php

use App\Enums\JobOrderStatus;
use App\Filament\Clusters\Reports\Pages\OpticalOrdersReport;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('optical orders report separates created, dispensed, and cancelled event cohorts', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $admin = User::factory()->admin()->create();

    $queued = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'fulfillment_mode' => 'prepared',
        'created_at' => '2026-08-01 09:00:00',
    ]);
    $inProgress = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'fulfillment_mode' => 'immediate',
        'uses_external_supplier' => true,
        'created_at' => '2026-08-10 09:00:00',
    ]);
    $dispensed = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'fulfillment_mode' => 'prepared',
        'created_at' => '2026-08-15 09:00:00',
        'dispensed_at' => '2026-08-17 09:00:00',
    ]);
    $cancelled = JobOrder::factory()->create([
        'status' => JobOrderStatus::Cancelled,
        'fulfillment_mode' => 'prepared',
        'created_at' => '2026-08-20 09:00:00',
        'cancelled_at' => '2026-08-21 09:00:00',
    ]);
    $createdOutside = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'created_at' => '2026-07-31 23:59:59',
        'dispensed_at' => '2026-08-05 09:00:00',
    ]);
    $cancelledOutside = JobOrder::factory()->create([
        'status' => JobOrderStatus::Cancelled,
        'created_at' => '2026-08-25 09:00:00',
        'cancelled_at' => '2026-08-31 00:00:00',
    ]);

    DispensingEvent::factory()->create([
        'job_order_id' => $dispensed->id,
        'billing_record_id' => null,
        'dispensed_by' => $admin->id,
        'dispensed_at' => '2026-08-17 09:00:00',
    ]);
    DispensingEvent::factory()->create([
        'job_order_id' => $createdOutside->id,
        'billing_record_id' => null,
        'dispensed_by' => $admin->id,
        'dispensed_at' => '2026-08-05 09:00:00',
    ]);
    DispensingEvent::factory()->create([
        'job_order_id' => $cancelledOutside->id,
        'billing_record_id' => null,
        'dispensed_by' => $admin->id,
        'dispensed_at' => '2026-08-31 00:00:00',
    ]);

    $this->actingAs($admin);

    $component = Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(OpticalOrdersReport::class);
    $stats = collect($component->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Orders created')?->getValue())
        ->toBe('5')
        ->and($stats->get('Dispensed')?->getValue())
        ->toBe('2')
        ->and($stats->get('Cancelled')?->getValue())
        ->toBe('1')
        ->and($stats->get('Avg. time to dispense')?->getValue())
        ->toBe('76.5 hours');

    $sections = collect($component->instance()->getSections())->keyBy('title');
    $statuses = collect($sections->get('Current status')['rows'])->keyBy('label');
    $modes = collect($sections->get('Fulfillment mode')['rows'])->keyBy('label');

    expect($statuses->get('Confirmed')['value'])
        ->toBe(1)
        ->and($statuses->get('Processing')['value'])
        ->toBe(1)
        ->and($statuses->get('Completed')['value'])
        ->toBe(1)
        ->and($statuses->get('Cancelled')['value'])
        ->toBe(2)
        ->and($modes->get('Prepared')['value'])
        ->toBe(4)
        ->and($modes->get('Immediate')['value'])
        ->toBe(1);

    $suppliers = collect($sections->get('Supplier mode')['rows'])->keyBy('label');

    expect($suppliers->get('In-house')['value'])
        ->toBe(4)
        ->and($suppliers->get('External supplier')['value'])
        ->toBe(1);
});

test('optical orders report returns a zero duration when nothing was dispensed in the period', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    JobOrder::factory()->create([
        'created_at' => '2026-08-30 09:00:00',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $stats = collect(Livewire::test(OpticalOrdersReport::class)->instance()->getStats())->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Dispensed')?->getValue())
        ->toBe('0')
        ->and($stats->get('Avg. time to dispense')?->getValue())
        ->toBe('0.0 hours');
});
