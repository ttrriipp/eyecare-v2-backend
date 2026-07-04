<?php

use App\Filament\Resources\Billings\Pages\CreateBilling;
use App\Models\Appointment;
use App\Models\Billing;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
});

test('staff can create a standalone billing', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($staff);

    Livewire::test(CreateBilling::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'notes' => 'Standalone consultation fee.',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Billing::class, [
        'customer_id' => $customer->id,
        'notes' => 'Standalone consultation fee.',
        'order_id' => null,
        'subtotal' => '0.00',
        'total_amount' => '0.00',
    ]);

    $billing = Billing::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($billing->status->name)->toBe('issued')
        ->and($billing->billing_number)->not->toBeNull()
        ->and($billing->issued_at)->not->toBeNull();
});

test('staff can create a standalone billing linked to an appointment', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($staff);

    Livewire::test(CreateBilling::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'appointment_id' => $appointment->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Billing::class, [
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
    ]);
});
