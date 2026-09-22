<?php

namespace Database\Factories;

use App\Enums\OrderPaymentMethod;
use App\Models\ClinicPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicPaymentMethod>
 */
class ClinicPaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method' => OrderPaymentMethod::GCash,
            'label' => OrderPaymentMethod::GCash->label(),
            'bank_name' => null,
            'account_name' => 'EyeCare Clinic',
            'account_number' => '09171234567',
            'qr_image_path' => null,
            'is_active' => true,
        ];
    }

    public function bankTransfer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => OrderPaymentMethod::BankTransfer,
            'label' => OrderPaymentMethod::BankTransfer->label(),
            'bank_name' => 'Demo Bank',
            'account_name' => 'EyeCare Clinic',
            'account_number' => '1234567890',
        ]);
    }
}
