<?php

namespace App\Actions\Orders;

use App\Models\DiscountType;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class ApplyDiscount
{
    /**
     * Validate and compute discount attributes without persisting.
     * Throws ValidationException if invalid.
     *
     * @return array<string, mixed>
     */
    public function computeAttributes(Order $order, int $discountTypeId, ?float $customAmount = null): array
    {
        $discountType = DiscountType::query()->findOrFail($discountTypeId);

        if (! $discountType->is_active) {
            throw ValidationException::withMessages([
                'discount_type_id' => ['The selected discount type is not active.'],
            ]);
        }

        $subtotal = (float) $order->subtotal;

        $discountAmount = match ($discountType->type) {
            'percentage' => $subtotal * ((float) $discountType->value / 100),
            'fixed' => $customAmount ?? (float) $discountType->value,
        };

        if ($discountAmount > $subtotal) {
            throw ValidationException::withMessages([
                'discount_type_id' => ['Discount cannot exceed the order subtotal.'],
            ]);
        }

        return [
            'discount_type_id' => $discountType->id,
            'discount_amount' => $discountAmount,
            'total_amount' => $subtotal - $discountAmount,
        ];
    }

    /**
     * Apply a discount directly to an order (for standalone use).
     *
     * @throws ValidationException
     */
    public function handle(Order $order, int $discountTypeId, ?float $customAmount = null): Order
    {
        $attributes = $this->computeAttributes($order, $discountTypeId, $customAmount);
        $order->update($attributes);

        return $order;
    }
}
