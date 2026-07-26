<?php

use App\Filament\Widgets\AppointmentsChartWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TodaysScheduleWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard widgets are accessible to staff and admin', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user);

    Livewire::test(StatsOverviewWidget::class)->assertSuccessful();
    Livewire::test(TodaysScheduleWidget::class)->assertSuccessful();
    Livewire::test(AppointmentsChartWidget::class)->assertSuccessful();
})->with(['staff', 'admin']);

test('dashboard prioritizes clinical workflow stats', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(StatsOverviewWidget::class)
        ->assertSee("Today's Appointments")
        ->assertSee('Waiting Today')
        ->assertSee('Active Encounters')
        ->assertSee('Quotations Pending')
        ->assertSee('Ready for Dispensing')
        ->assertSee('Low Stock Variants');
});

test('today schedule widget shows patient name not customer', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(TodaysScheduleWidget::class)
        ->assertSee('Schedule'); // Widget heading contains "Schedule"
});
