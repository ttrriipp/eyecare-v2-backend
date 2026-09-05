<?php

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Clusters\Reports\Pages\AppointmentsReport;
use App\Filament\Clusters\Reports\Pages\FeedbackReport;
use App\Filament\Clusters\Reports\Pages\FinancialReport;
use App\Filament\Clusters\Reports\Pages\OpticalOrdersReport;
use App\Filament\Widgets\ReportChartWidget;
use App\Models\Appointment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\FrameRating;
use App\Models\JobOrder;
use App\Models\User;
use App\Models\VisitRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $this->actingAs(User::factory()->admin()->create());
});

test('appointments report exposes outcome and type charts', function (): void {
    Appointment::factory()->count(2)->create([
        'scheduled_at' => '2026-08-15 09:00:00',
    ]);

    $charts = collect(Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(AppointmentsReport::class)->instance()->getCharts())->keyBy('key');

    expect($charts->get('appointment-outcomes')['type'])
        ->toBe('doughnut')
        ->and($charts->get('appointment-types')['type'])
        ->toBe('bar')
        ->and(data_get($charts->get('appointment-outcomes'), 'data.datasets.0.data'))
        ->toContain(2);
});

test('financial report exposes bill status and charge source charts', function (): void {
    $billingRecord = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 1200,
        'recorded_at' => '2026-08-15 09:00:00',
    ]);
    BillingRecordItem::factory()->service()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => 1200,
    ]);

    $charts = collect(Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(FinancialReport::class)->instance()->getCharts())->keyBy('key');

    expect($charts->get('bill-status')['type'])
        ->toBe('doughnut')
        ->and($charts->get('charge-source')['type'])
        ->toBe('bar')
        ->and(data_get($charts->get('charge-source'), 'data.datasets.0.data'))
        ->toContain(1200.0);
});

test('optical orders report exposes status and supplier charts', function (): void {
    JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'uses_external_supplier' => false,
        'created_at' => '2026-08-15 09:00:00',
    ]);

    $charts = collect(Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(OpticalOrdersReport::class)->instance()->getCharts())->keyBy('key');

    expect($charts->get('order-status')['type'])
        ->toBe('bar')
        ->and($charts->get('supplier-mode')['type'])
        ->toBe('doughnut')
        ->and(data_get($charts->get('order-status'), 'data.datasets.0.data'))
        ->toContain(1);
});

test('feedback report exposes a comparative rating distribution chart', function (): void {
    VisitRating::factory()->create([
        'rating' => 5,
        'created_at' => '2026-08-15 09:00:00',
    ]);
    FrameRating::factory()->create([
        'rating' => 4,
        'created_at' => '2026-08-15 09:00:00',
    ]);

    $charts = collect(Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(FeedbackReport::class)->instance()->getCharts())->keyBy('key');

    expect($charts->get('feedback-distribution')['type'])
        ->toBe('bar')
        ->and(data_get($charts->get('feedback-distribution'), 'data.datasets'))
        ->toHaveCount(2)
        ->and(data_get($charts->get('feedback-distribution'), 'data.datasets.0.data.4'))
        ->toBe(1);
});

test('report chart widget renders configured chart types and data', function (): void {
    $component = Livewire::test(ReportChartWidget::class, [
        'chartType' => 'doughnut',
        'chartData' => [
            'labels' => ['Paid', 'Unpaid'],
            'datasets' => [['data' => [3, 1]]],
        ],
        'chartHeading' => 'Bill status',
    ]);

    $widget = $component->instance();
    $data = (fn (): array => $this->getData())->call($widget);
    $type = (fn (): string => $this->getType())->call($widget);
    $maxHeight = (fn (): ?string => $this->getMaxHeight())->call($widget);

    expect($type)
        ->toBe('doughnut')
        ->and($data['datasets'][0]['data'])
        ->toBe([3, 1])
        ->and($maxHeight)
        ->toBe('300px')
        ->and($component)
        ->assertSee('Bill status')
        ->assertSee('data-chart-type="doughnut"', false);
});

test('report page renders charts for the selected period', function (): void {
    $billingRecord = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 1200,
        'recorded_at' => '2026-08-15 09:00:00',
    ]);
    BillingRecordItem::factory()->service()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => 1200,
    ]);

    Livewire::withQueryParams([
        'dateFrom' => '2026-08-01',
        'dateUntil' => '2026-08-30',
    ])->test(FinancialReport::class)
        ->assertSee('Report charts', false)
        ->assertSee('Bill status')
        ->assertSee('Charges by source')
        ->assertSee('data-chart-type="doughnut"', false)
        ->assertSee('data-chart-type="bar"', false);
});
