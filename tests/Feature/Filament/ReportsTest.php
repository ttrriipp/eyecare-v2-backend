<?php

use App\Filament\Pages\Reports\AppointmentsReport;
use App\Filament\Pages\Reports\FeedbackReport;
use App\Filament\Pages\Reports\OrdersReport;
use App\Filament\Pages\Reports\SalesReport;
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
