<?php

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('payment method enum defines the five canonical methods', function () {
    expect(PaymentMethod::cases())->toHaveCount(5)
        ->and(collect(PaymentMethod::cases())->pluck('value')->all())->toBe([
            'cash', 'gcash', 'bank_transfer', 'credit_card', 'check',
        ]);
});

test('invoice payment casts payment_method to enum', function () {
    $invoice = Invoice::factory()->create();
    $payment = InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_method' => 'cash',
    ]);

    expect($payment->payment_method)->toBe(PaymentMethod::Cash)
        ->and($payment->payment_method->value)->toBe('cash');
});

test('invoice payment accepts all valid payment methods', function () {
    $invoice = Invoice::factory()->create();

    foreach (PaymentMethod::cases() as $method) {
        $payment = InvoicePayment::factory()->create([
            'invoice_id' => $invoice->id,
            'payment_method' => $method->value,
        ]);

        expect($payment->payment_method)->toBe($method);
    }
});
