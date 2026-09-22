<?php

namespace Database\Factories;

use App\Enums\DiscountProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessoryOrderRequestDiscountProof>
 */
class AccessoryOrderRequestDiscountProofFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accessory_order_request_id' => AccessoryOrderRequest::factory(),
            'user_id' => User::factory()->patient(),
            'status' => DiscountProofStatus::Pending,
            'file_path' => 'discount-proofs/example.jpg',
            'original_name' => 'discount-proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ];
    }
}
