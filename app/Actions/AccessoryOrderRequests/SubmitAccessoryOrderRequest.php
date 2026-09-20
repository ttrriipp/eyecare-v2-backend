<?php

namespace App\Actions\AccessoryOrderRequests;

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\CommercialItemKind;
use App\Exceptions\ActiveOrderRequestExistsException;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitAccessoryOrderRequest
{
    /**
     * Submit a new accessory order request.
     *
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $items
     */
    public function handle(
        User $account,
        array $items,
        string $requestedDiscountType = 'none',
    ): AccessoryOrderRequest {
        return DB::transaction(function () use ($account, $items, $requestedDiscountType): AccessoryOrderRequest {
            // Lock account and check one-pending-request limit
            $account = User::query()->lockForUpdate()->findOrFail($account->id);

            $existingPending = AccessoryOrderRequest::query()
                ->where('user_id', $account->id)
                ->where('status', AccessoryOrderRequestStatus::Pending)
                ->exists();

            if ($existingPending) {
                throw new ActiveOrderRequestExistsException;
            }

            // Validate items
            if (count($items) < 1 || count($items) > 20) {
                throw ValidationException::withMessages([
                    'items' => ['A request must contain between 1 and 20 items.'],
                ]);
            }

            // Load and validate variants
            $variantIds = collect($items)->pluck('product_variant_id')->unique();
            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('product_type', 'accessory')->where('is_active', true))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemData = [];

            foreach ($items as $item) {
                $variantId = $item['product_variant_id'];
                $quantity = max(1, min(5, (int) $item['quantity']));

                $variant = $variants->get($variantId);

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Product variant {$variantId} is not an active accessory."],
                    ]);
                }

                if ($variant->usableStockQuantity() < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for {$variant->name}."],
                    ]);
                }

                $amount = (int) ($variant->price * 100) * $quantity;
                $subtotal += $amount;

                $itemData[] = [
                    'product_variant_id' => $variant->id,
                    'description' => $variant->product->name.' — '.$variant->name,
                    'quantity' => $quantity,
                    'unit_price' => $variant->price,
                    'amount' => $amount / 100,
                    'item_kind' => CommercialItemKind::Accessory,
                    'item_snapshot' => [
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'variant_name' => $variant->name,
                        'product_name' => $variant->product->name,
                        'price' => $variant->price,
                        'attributes' => $variant->attributes,
                    ],
                ];
            }

            // Create request
            $request = AccessoryOrderRequest::create([
                'user_id' => $account->id,
                'patient_id' => $account->patient->id,
                'status' => AccessoryOrderRequestStatus::Pending,
                'subtotal_amount' => $subtotal / 100,
                'requested_discount_type' => $requestedDiscountType,
            ]);

            // Create items
            foreach ($itemData as $data) {
                $data['accessory_order_request_id'] = $request->id;
                AccessoryOrderRequestItem::create($data);
            }

            return $request->fresh(['items']);
        });
    }
}
