<?php

use App\Filament\Clusters\Reports\Pages\AppointmentsReport;
use App\Filament\Clusters\Reports\Pages\FeedbackReport;
use App\Filament\Clusters\Reports\Pages\FinancialReport;
use App\Filament\Clusters\Reports\Pages\OpticalOrdersReport;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('every report exports metadata, metric definitions, and aggregate values only', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $billingRecord = BillingRecord::factory()->create([
        'total_amount' => 500,
        'recorded_at' => '2026-08-30 09:00:00',
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'payment_method' => '=cmd',
        'amount' => 100,
        'recorded_at' => '2026-08-30 09:30:00',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    foreach ([FinancialReport::class, AppointmentsReport::class, OpticalOrdersReport::class, FeedbackReport::class] as $pageClass) {
        $component = Livewire::withQueryParams([
            'dateFrom' => '2026-08-01',
            'dateUntil' => '2026-08-30',
        ])->test($pageClass);

        expect($component->instance()->getMetricDefinitions())->not->toBeEmpty();

        $component->call('exportCsv')->assertFileDownloaded(
            Str::slug((string) $component->instance()->getTitle(), '_').'_2026_08_30.csv',
            null,
            'text/csv; charset=UTF-8',
        );

        $content = base64_decode(data_get($component->effects, 'download.content'), true);

        expect($content)
            ->toContain('Period')
            ->toContain('2026-08-01 – 2026-08-30')
            ->toContain('Asia/Manila')
            ->toContain('Metric definitions')
            ->not->toContain('patient_id')
            ->not->toContain('Visible visit comment');

        if ($pageClass === FinancialReport::class) {
            expect($content)
                ->toContain("'=Cmd")
                ->toContain('"Net billed",500.00')
                ->not->toContain('₱');
        }
    }
});

test('report page renders accessible filter controls and empty states', function () {
    $this->travelTo(Carbon::parse('2026-08-30 10:15:00', 'Asia/Manila'));
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(FeedbackReport::class)
        ->assertSee('Report period')
        ->assertSee('Report period presets')
        ->assertSee('Apply range')
        ->assertSee('Preparing export...')
        ->assertSee('Applying range...')
        ->assertSee('Updating report...')
        ->assertSee('No records in this period')
        ->assertSee('Try a different date range.');
});
