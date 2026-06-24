<?php

use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\CreateServiceBilling;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\BillingStatus;
use App\Models\Service;
use App\Models\ServiceRecord;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
});

// ─── AddServiceToBilling ──────────────────────────────────────────────────────

it('adds a service line item to an existing billing', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '0.00', 'subtotal' => '0.00', 'balance_due' => '0.00']);
    $service = Service::factory()->create(['price' => '800.00']);

    $item = app(AddServiceToBilling::class)->handle($billing, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'performed_at' => now(),
    ]);

    expect($item)->toBeInstanceOf(BillingItem::class)
        ->and($item->type)->toBe('service')
        ->and($item->amount)->toBe('800.00')
        ->and($item->service_record_id)->not->toBeNull();

    $billing->refresh();
    expect($billing->subtotal)->toBe('800.00')
        ->and($billing->total_amount)->toBe('800.00')
        ->and($billing->balance_due)->toBe('800.00');
});

it('allows amount override when adding a service', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '0.00', 'subtotal' => '0.00', 'balance_due' => '0.00']);
    $service = Service::factory()->create(['price' => '800.00']);

    app(AddServiceToBilling::class)->handle($billing, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'amount' => '600.00',
        'performed_at' => now(),
    ]);

    $billing->refresh();
    expect($billing->total_amount)->toBe('600.00');
});

it('creates a service_record when adding a service item', function () {
    $staff = User::factory()->staff()->create();
    $billing = Billing::factory()->issued()->create(['total_amount' => '0.00', 'subtotal' => '0.00', 'balance_due' => '0.00']);
    $service = Service::factory()->create(['price' => '500.00']);

    app(AddServiceToBilling::class)->handle($billing, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'performed_at' => now(),
    ]);

    expect(ServiceRecord::query()->where('customer_id', $billing->customer_id)->exists())->toBeTrue();
});

it('throws when adding service to a voided billing', function () {
    $staff = User::factory()->staff()->create();
    $voidedStatus = BillingStatus::query()->where('name', 'voided')->firstOrFail();
    $billing = Billing::factory()->create(['billing_status_id' => $voidedStatus->id]);
    $service = Service::factory()->create();

    expect(fn () => app(AddServiceToBilling::class)->handle($billing, [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'performed_at' => now(),
    ]))->toThrow(ValidationException::class);
});

// ─── CreateServiceBilling ─────────────────────────────────────────────────────

it('creates a standalone service billing', function () {
    $customer = User::factory()->customer()->create();
    $staff = User::factory()->staff()->create();
    $service = Service::factory()->create(['price' => '300.00']);

    $billing = app(CreateServiceBilling::class)->handle([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'performed_at' => now(),
    ]);

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->customer_id)->toBe($customer->id)
        ->and($billing->order_id)->toBeNull()
        ->and($billing->status->name)->toBe('issued')
        ->and($billing->total_amount)->toBe('300.00')
        ->and($billing->items)->toHaveCount(1);
});
