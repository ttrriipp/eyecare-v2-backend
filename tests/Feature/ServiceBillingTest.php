<?php

use App\Actions\Billing\GenerateBillingForService;
use App\Models\Billing;
use App\Models\ServiceRecord;
use Database\Seeders\BillingStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
});

it('generates a billing record from a service record', function () {
    $serviceRecord = ServiceRecord::factory()->create([
        'amount' => '800.00',
        'discount_amount' => '0.00',
        'total_amount' => '800.00',
    ]);

    $billing = app(GenerateBillingForService::class)->handle($serviceRecord);

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->billable_id)->toBe($serviceRecord->id)
        ->and($billing->billable_type)->toBe(ServiceRecord::class)
        ->and($billing->total_amount)->toBe('800.00')
        ->and($billing->balance_due)->toBe('800.00')
        ->and($billing->amount_paid)->toBe('0.00')
        ->and($billing->status->name)->toBe('issued')
        ->and($billing->issued_at)->not->toBeNull();

    $this->assertDatabaseHas(Billing::class, [
        'billable_type' => ServiceRecord::class,
        'billable_id' => $serviceRecord->id,
        'total_amount' => '800.00',
    ]);
});

it('billing total reflects service record total_amount including discount', function () {
    $serviceRecord = ServiceRecord::factory()->create([
        'amount' => '800.00',
        'discount_amount' => '160.00',
        'total_amount' => '640.00',
    ]);

    $billing = app(GenerateBillingForService::class)->handle($serviceRecord);

    expect($billing->total_amount)->toBe('640.00')
        ->and($billing->balance_due)->toBe('640.00');
});

it('prevents generating a second billing for the same service record', function () {
    $serviceRecord = ServiceRecord::factory()->create(['total_amount' => '500.00']);

    app(GenerateBillingForService::class)->handle($serviceRecord);

    expect(fn () => app(GenerateBillingForService::class)->handle($serviceRecord))
        ->toThrow(ValidationException::class);

    expect(
        Billing::where('billable_type', ServiceRecord::class)
            ->where('billable_id', $serviceRecord->id)
            ->count()
    )->toBe(1);
});

it('service record model exposes a billing relationship', function () {
    $serviceRecord = ServiceRecord::factory()->create(['total_amount' => '300.00']);

    expect($serviceRecord->billing)->toBeNull();

    app(GenerateBillingForService::class)->handle($serviceRecord);

    expect($serviceRecord->fresh()->billing)->toBeInstanceOf(Billing::class);
});
