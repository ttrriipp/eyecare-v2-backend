<?php

use App\Filament\Resources\JobOrders\JobOrderResource;
use App\Filament\Resources\JobOrders\Pages\EditJobOrder;
use App\Filament\Resources\JobOrders\Pages\ListJobOrders;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list job orders', function () {
    $staff = User::factory()->staff()->create();
    $jobOrders = JobOrder::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListJobOrders::class)
        ->assertCanSeeTableRecords($jobOrders);
});

test('staff can view a job order', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditJobOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSuccessful();
});

test('job order resource is registered', function () {
    expect(JobOrderResource::getModel())->toBe(JobOrder::class);
});
