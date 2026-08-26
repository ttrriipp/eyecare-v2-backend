<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedFrame>
 */
class SavedFrameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_variant_id' => ProductVariant::factory(),
        ];
    }

    public function forAccount(User $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $account->id,
        ]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_variant_id' => $variant->id,
        ]);
    }
}
