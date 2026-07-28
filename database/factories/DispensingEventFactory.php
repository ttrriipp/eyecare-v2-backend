<?php

namespace Database\Factories;

use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispensingEvent>
 */
class DispensingEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_order_id' => JobOrder::factory(),
            'billing_record_id' => BillingRecord::factory(),
            'dispensed_by' => User::factory()->staff(),
            'recipient_name' => null,
            'notes' => null,
            'dispensed_at' => now(),
        ];
    }
}
