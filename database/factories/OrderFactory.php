<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 50, 500);

        return [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'customer_id' => User::factory()->customer(),
            'appointment_id' => null,
            'prescription_id' => null,
            'order_status_id' => $this->requestedStatusId(),
            'is_non_prescription' => true,
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'discount_amount' => 0,
            'confirmed_at' => null,
            'completed_at' => null,
        ];
    }

    private function requestedStatusId(): int
    {
        return OrderStatus::query()->firstOrCreate([
            'name' => 'requested',
        ])->id;
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'order_status_id' => OrderStatus::query()->firstOrCreate([
                'name' => 'completed',
            ])->id,
            'confirmed_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
