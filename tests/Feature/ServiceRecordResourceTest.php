<?php

use App\Filament\Resources\ServiceRecords\Pages\CreateServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Models\Billing;
use App\Models\Service;
use App\Models\ServiceRecord;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
    $this->actingAs(User::factory()->staff()->create());
});

test('staff can list service records', function () {
    $records = ServiceRecord::factory()->count(2)->create();

    Livewire::test(ListServiceRecords::class)
        ->assertCanSeeTableRecords($records);
});

test('creating a service record auto-generates a billing', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $service = Service::factory()->create(['price' => '800.00']);

    Livewire::test(CreateServiceRecord::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'amount' => '800.00',
            'discount_amount' => '0.00',
            'total_amount' => '800.00',
            'staff_id' => $staff->id,
            'performed_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $serviceRecord = ServiceRecord::query()->latest()->first();
    expect($serviceRecord)->not->toBeNull();

    $billing = Billing::query()
        ->where('billable_type', ServiceRecord::class)
        ->where('billable_id', $serviceRecord->id)
        ->first();

    expect($billing)->not->toBeNull()
        ->and($billing->total_amount)->toBe('800.00')
        ->and($billing->status->name)->toBe('issued');
});
