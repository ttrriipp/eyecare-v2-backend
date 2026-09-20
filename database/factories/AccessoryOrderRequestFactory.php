<?php

namespace Database\Factories;

use App\Enums\AccessoryOrderRequestStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessoryOrderRequest>
 */
class AccessoryOrderRequestFactory extends Factory
{
    protected $model = AccessoryOrderRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_number' => 'ORQ-2026-'.str_pad($this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'user_id' => User::factory()->patient(),
            'patient_id' => Patient::factory(),
            'status' => AccessoryOrderRequestStatus::Pending,
            'subtotal_amount' => $this->faker->numberBetween(500, 50000),
            'requested_discount_type' => 'none',
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccessoryOrderRequestStatus::Accepted,
            'resolved_by' => User::factory()->staff(),
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccessoryOrderRequestStatus::Rejected,
            'resolved_by' => User::factory()->staff(),
            'resolved_at' => now(),
            'rejection_reason' => 'Test rejection',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccessoryOrderRequestStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
