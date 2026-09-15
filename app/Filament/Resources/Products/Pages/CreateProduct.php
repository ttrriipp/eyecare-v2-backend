<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Inventory\RecordInventoryMovement;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $product = parent::handleRecordCreation($data);

        // Create inventory movements for opening stock on each variant.
        // This ensures every positive stock quantity has ledger provenance.
        foreach ($product->variants as $variant) {
            $openingStock = (int) ($variant->stock_quantity ?? 0);

            if ($openingStock <= 0) {
                continue;
            }

            // Reset variant stock to zero, then use the inventory action
            // to record the opening movement with proper provenance.
            $variant->update(['stock_quantity' => 0]);

            app(RecordInventoryMovement::class)->handle(
                variant: $variant,
                quantityChange: $openingStock,
                type: 'opening_stock',
                notes: 'Opening stock at variant creation.',
                actingUser: auth()->user(),
            );
        }

        return $product->fresh();
    }
}
