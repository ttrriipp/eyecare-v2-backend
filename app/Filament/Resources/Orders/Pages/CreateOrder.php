<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        return DB::transaction(function () use ($data, $items): Model {
            $subtotal = '0.00';
            $lineItems = [];

            foreach ($items as $item) {
                $variant = ProductVariant::query()->with('product')->findOrFail($item['product_variant_id']);
                $lensType = LensType::query()->findOrFail($item['lens_type_id']);
                $quantity = (int) $item['quantity'];
                $unitPrice = (string) $variant->price;
                $lensTypePrice = $lensType->price !== null ? (string) $lensType->price : '0.00';
                $lineSubtotal = bcmul(bcadd($unitPrice, $lensTypePrice, 2), (string) $quantity, 2);
                $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                $lineItems[] = [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'variant_sku' => $variant->sku,
                    'lens_type_name' => $lensType->name,
                    'lens_type_price' => $lensType->price !== null ? (string) $lensType->price : null,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $data['subtotal'] = $subtotal;
            $data['total_amount'] = $subtotal;
            $data['discount_amount'] = '0.00';
            $data['order_status_id'] = OrderStatus::query()->where('name', 'requested')->value('id');

            /** @var Order $order */
            $order = static::getModel()::create($data);
            $order->items()->createMany($lineItems);

            return $order;
        });
    }
}
