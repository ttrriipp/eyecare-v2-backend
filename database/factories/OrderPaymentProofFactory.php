<?php

namespace Database\Factories;

use App\Enums\OrderPaymentMethod;
use App\Enums\OrderPaymentProofStatus;
use App\Models\JobOrder;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPaymentProof>
 */
class OrderPaymentProofFactory extends Factory
{
    protected $model = OrderPaymentProof::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_order_id' => JobOrder::factory(),
            'user_id' => User::factory()->patient(),
            'status' => OrderPaymentProofStatus::Pending,
            'payment_method' => OrderPaymentMethod::GCash,
            'file_path' => 'payment-proofs/'.$this->faker->uuid().'.jpg',
            'original_name' => 'proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $this->faker->numberBetween(10000, 5000000),
            'sender_name' => $this->faker->name(),
            'reference_number' => $this->faker->numerify('GCASH-######'),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderPaymentProofStatus::Accepted,
            'reviewed_by' => User::factory()->staff(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderPaymentProofStatus::Rejected,
            'reviewed_by' => User::factory()->staff(),
            'reviewed_at' => now(),
            'rejection_reason' => 'Test rejection',
        ]);
    }
}
