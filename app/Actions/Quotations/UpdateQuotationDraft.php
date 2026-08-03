<?php

namespace App\Actions\Quotations;

use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateQuotationDraft
{
    /**
     * Update a draft or presented quotation's commercial data.
     *
     * Editing a Presented quotation clears presentation metadata and returns it to Draft.
     * Accepted, declined, and expired quotations are immutable.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Quotation $quotation, array $data): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Presented], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft or presented quotations can be edited.'],
            ]);
        }

        $validated = $this->validate($data);

        return DB::transaction(function () use ($quotation, $validated): Quotation {
            $itemSnapshots = collect($validated['items'])->map(function (array $item): array {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                // Derive item type
                $hasProductReference = filled($item['product_variant_id'] ?? null) || filled($item['lens_category_id'] ?? null);
                $itemType = $hasProductReference ? TransactionItemType::Product : TransactionItemType::Service;

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => self::formatMoney($unitPriceInCents),
                    'amount' => self::formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'item_type' => $itemType,
                    'amount_in_cents' => $amountInCents,
                ];
            });

            $subtotalInCents = $itemSnapshots->sum('amount_in_cents');
            $discountInCents = (int) round(((float) ($validated['discount_amount'] ?? 0)) * 100);

            if ($discountInCents > $subtotalInCents) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['The discount cannot exceed the quotation subtotal.'],
                ]);
            }

            $totalInCents = $subtotalInCents - $discountInCents;

            // Clear presentation metadata if returning from Presented to Draft
            $updateData = [
                'subtotal' => self::formatMoney($subtotalInCents),
                'discount_amount' => self::formatMoney($discountInCents),
                'total' => self::formatMoney($totalInCents),
                'valid_until' => $validated['valid_until'] ?? $quotation->valid_until,
                'notes' => self::nullableTrimmed($validated['notes'] ?? $quotation->notes),
                'internal_notes' => self::nullableTrimmed($validated['internal_notes'] ?? $quotation->internal_notes),
            ];

            if ($quotation->status === QuotationStatus::Presented) {
                $updateData['status'] = QuotationStatus::Draft;
                $updateData['presented_by'] = null;
                $updateData['presented_at'] = null;
            }

            $quotation->update($updateData);

            // Replace items atomically
            $quotation->items()->delete();
            $quotation->items()->createMany(
                $itemSnapshots
                    ->map(fn (array $item): array => collect($item)->except('amount_in_cents')->all())
                    ->all(),
            );

            return $quotation->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'discount_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'items.*.product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true),
            ],
            'items.*.lens_category_id' => ['nullable', 'integer', Rule::exists('lens_categories', 'id')],
        ]);

        $validator->after(function ($validator) use ($data): void {
            foreach ($data['items'] ?? [] as $index => $item) {
                if (filled($item['product_variant_id'] ?? null) && filled($item['lens_category_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.product_variant_id",
                        'A quotation item can reference either a catalog item or a lens category, not both.',
                    );
                }
            }

            $variantIds = collect($data['items'] ?? [])
                ->pluck('product_variant_id')
                ->filter()
                ->unique()
                ->values();

            if ($variantIds->isEmpty()) {
                return;
            }

            $activeVariantIds = ProductVariant::query()
                ->active()
                ->whereIn('id', $variantIds)
                ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
                ->pluck('id');

            foreach ($variantIds->diff($activeVariantIds) as $invalidVariantId) {
                $validator->errors()->add(
                    'items',
                    "Product variant {$invalidVariantId} is not available for quotation.",
                );
            }
        });

        return $validator->validate();
    }

    private static function formatMoney(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }

    private static function nullableTrimmed(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return trim($value);
    }
}
