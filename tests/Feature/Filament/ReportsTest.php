<?php

use App\Filament\Pages\Reports\AppointmentsReport;
use App\Filament\Pages\Reports\FeedbackReport;
use App\Filament\Pages\Reports\OrdersReport;
use App\Filament\Pages\Reports\SalesReport;
use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can access all report pages', function (string $pageClass) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test($pageClass)
        ->assertSuccessful();
})->with([
    'Sales' => [SalesReport::class],
    'Orders' => [OrdersReport::class],
    'Appointments' => [AppointmentsReport::class],
    'Feedback' => [FeedbackReport::class],
]);

test('staff cannot access report pages', function (string $pageClass) {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test($pageClass)
        ->assertForbidden();
})->with([
    'Sales' => [SalesReport::class],
    'Orders' => [OrdersReport::class],
    'Appointments' => [AppointmentsReport::class],
    'Feedback' => [FeedbackReport::class],
]);

test('sales report shows correct stats for date range', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(SalesReport::class)
        ->assertSuccessful()
        ->assertSee('Total billings')
        ->assertSee('Total paid');
});

test('default preset on mount is this month', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SalesReport::class)
        ->assertSet('activePreset', 'this_month')
        ->assertSet('dateFrom', now()->startOfMonth()->toDateString())
        ->assertSet('dateUntil', now()->toDateString());
});

test('applying the last month preset sets the correct range', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SalesReport::class)
        ->call('applyPreset', 'last_month')
        ->assertSet('activePreset', 'last_month')
        ->assertSet('dateFrom', now()->subMonthNoOverflow()->startOfMonth()->toDateString())
        ->assertSet('dateUntil', now()->subMonthNoOverflow()->endOfMonth()->toDateString());
});

test('all time preset clears the date range', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SalesReport::class)
        ->call('applyPreset', 'all_time')
        ->assertSet('dateFrom', null)
        ->assertSet('dateUntil', null);
});

test('manually changing a date clears the active preset', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SalesReport::class)
        ->assertSet('activePreset', 'this_month')
        ->set('dateFrom', '2026-01-01')
        ->assertSet('activePreset', null);
});

test('breakdown shows a share percentage when records exist', function () {
    $this->actingAs(User::factory()->admin()->create());

    Billing::factory()->issued()->create(['issued_at' => now()]);

    Livewire::test(SalesReport::class)
        ->assertSee('100%')
        ->assertDontSee('No records in this period');
});

test('breakdown shows an empty state when no records in range', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SalesReport::class)
        ->set('dateFrom', '2000-01-01')
        ->set('dateUntil', '2000-01-02')
        ->assertSee('No records in this period');
});
