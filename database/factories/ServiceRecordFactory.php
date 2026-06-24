<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRecord>
 */
class ServiceRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory()->customer(),
            'service_id' => Service::factory(),
            'appointment_id' => null,
            'staff_id' => User::factory()->staff(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'notes' => null,
            'performed_at' => now(),
        ];
    }
}
