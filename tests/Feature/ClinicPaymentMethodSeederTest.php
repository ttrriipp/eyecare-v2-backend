<?php

use App\Enums\OrderPaymentMethod;
use App\Models\ClinicPaymentMethod;
use Database\Seeders\ClinicPaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeds configured online payment methods without overwriting admin changes', function (): void {
    config([
        'payments.gcash_account_name' => 'GCash Demo Account',
        'payments.gcash_account_number' => '09999999999',
        'payments.gcash_qr_image_path' => 'payment-methods/gcash.png',
        'payments.bank_name' => 'BPI',
        'payments.bank_account_name' => 'BPI Demo Account',
        'payments.bank_account_number' => '1234567890',
        'payments.bank_qr_image_path' => 'payment-methods/bpi.png',
    ]);

    $this->seed(ClinicPaymentMethodSeeder::class);

    $gcash = ClinicPaymentMethod::query()->where('method', OrderPaymentMethod::GCash)->firstOrFail();
    $bankTransfer = ClinicPaymentMethod::query()->where('method', OrderPaymentMethod::BankTransfer)->firstOrFail();

    expect($gcash->label)->toBe('GCash')
        ->and($gcash->account_name)->toBe('GCash Demo Account')
        ->and($gcash->account_number)->toBe('09999999999')
        ->and($gcash->qr_image_path)->toBe('payment-methods/gcash.png')
        ->and($bankTransfer->label)->toBe('Bank transfer')
        ->and($bankTransfer->bank_name)->toBe('BPI')
        ->and($bankTransfer->account_name)->toBe('BPI Demo Account')
        ->and($bankTransfer->account_number)->toBe('1234567890')
        ->and($bankTransfer->qr_image_path)->toBe('payment-methods/bpi.png')
        ->and($gcash->is_active)->toBeTrue()
        ->and($bankTransfer->is_active)->toBeTrue();

    $gcash->update(['account_name' => 'Admin Updated Account']);

    $this->seed(ClinicPaymentMethodSeeder::class);

    expect($gcash->fresh()->account_name)->toBe('Admin Updated Account');
});
