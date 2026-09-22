<?php

namespace Database\Seeders;

use App\Enums\OrderPaymentMethod;
use App\Models\ClinicPaymentMethod;
use Illuminate\Database\Seeder;

class ClinicPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMethod(
            method: OrderPaymentMethod::GCash,
            accountName: $this->configString('payments.gcash_account_name'),
            accountNumber: $this->configString('payments.gcash_account_number'),
            bankName: null,
            qrImagePath: $this->configString('payments.gcash_qr_image_path'),
        );

        $this->seedMethod(
            method: OrderPaymentMethod::BankTransfer,
            accountName: $this->configString('payments.bank_account_name'),
            accountNumber: $this->configString('payments.bank_account_number'),
            bankName: $this->configString('payments.bank_name'),
            qrImagePath: $this->configString('payments.bank_qr_image_path'),
        );
    }

    private function seedMethod(
        OrderPaymentMethod $method,
        ?string $accountName,
        ?string $accountNumber,
        ?string $bankName,
        ?string $qrImagePath,
    ): void {
        if ($accountName === null || $accountNumber === null) {
            return;
        }

        if ($method === OrderPaymentMethod::BankTransfer && $bankName === null) {
            return;
        }

        ClinicPaymentMethod::query()->firstOrCreate(
            ['method' => $method->value],
            [
                'label' => $method->label(),
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'qr_image_path' => $qrImagePath,
                'is_active' => true,
            ],
        );
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return filled($value) ? trim((string) $value) : null;
    }
}
