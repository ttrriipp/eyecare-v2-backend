<?php

namespace App\Actions\SavedFrames;

use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
        $isEligible = ProductVariant::query()
            ->whereKey($variant->id)
            ->active()
            ->whereHas(
                'product',
                fn (Builder $productQuery): Builder => $productQuery->where('product_type', 'frame'),
            )
            ->exists();

        if (! $isEligible) {
            abort(422, 'This frame variant cannot be saved.');
        }
    }
}
