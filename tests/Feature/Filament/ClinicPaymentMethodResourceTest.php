<?php

use App\Enums\OrderPaymentMethod;
use App\Filament\Resources\ClinicPaymentMethods\ClinicPaymentMethodResource;
use App\Filament\Resources\ClinicPaymentMethods\Pages\CreateClinicPaymentMethod;
use App\Filament\Resources\ClinicPaymentMethods\Pages\ListClinicPaymentMethods;
use App\Models\ClinicPaymentMethod;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('admin can create and list an online clinic payment method', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateClinicPaymentMethod::class)
        ->fillForm([
            'method' => OrderPaymentMethod::BankTransfer->value,
            'label' => 'BPI Bank Transfer',
            'bank_name' => 'BPI',
            'account_name' => 'EyeCare Clinic',
            'account_number' => '1234567890',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $method = ClinicPaymentMethod::query()->firstOrFail();

    expect($method->method)->toBe(OrderPaymentMethod::BankTransfer)
        ->and($method->bank_name)->toBe('BPI');

    Livewire::test(ListClinicPaymentMethods::class)
        ->assertCanSeeTableRecords([$method]);
});

test('staff cannot view clinic payment method settings', function (): void {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    expect(ClinicPaymentMethodResource::canViewAny())->toBeFalse();

    $this->get(ClinicPaymentMethodResource::getUrl('index', panel: 'admin'))
        ->assertForbidden();
});
