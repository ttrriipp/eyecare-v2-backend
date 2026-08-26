<?php

namespace App\Actions\SavedFrames;

use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveFrame
{
    public function handle(User $account, ProductVariant $variant): SavedFrame
    {
        $this->ensureVariantCanBeSaved($variant);

        DB::transaction(function () use ($account, $variant): void {
            SavedFrame::query()->insertOrIgnore([
                'user_id' => $account->id,
                'product_variant_id' => $variant->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return SavedFrame::query()
            ->where('user_id', $account->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();
    }

    private function ensureVariantCanBeSaved(ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        if ($variant->trashed()
            || ! $variant->is_active
            || $variant->product === null
            || $variant->product->trashed()
            || ! $variant->product->is_active
            || $variant->product->product_type !== 'frame'
        ) {
            abort(422, 'This frame variant cannot be saved.');
        }
    }
}
