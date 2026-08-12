<?php

namespace App\Actions\Quotations;

use App\Enums\QuotationStatus;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateQuotationDraft
{
    /**
     * Update a draft quotation's commercial data.
     *
     * Accepted and declined quotations are immutable.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Quotation $quotation, array $data, ?User $editor = null): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'quotation' => ['Only draft quotations can be edited.'],
            ]);
        }

        $validated = $this->validate($data);

        return DB::transaction(function () use ($quotation, $validated, $editor): Quotation {
            $itemSnapshots = collect($validated['items'])->map(function (array $item): array {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                $hasProductReference = filled($item['product_variant_id'] ?? null)
                    || filled($item['lens_category_id'] ?? null)
                    || filled($item['lens_option_id'] ?? null);

                // Build immutable catalog snapshot
                $snapshotResult = app(BuildQuotationItemSnapshot::class)->handle(
                    productVariantId: $item['product_variant_id'] ?? null,
                    lensCategoryId: $item['lens_category_id'] ?? null,
                    explicitKind: ($item['item_kind'] ?? null) === 'custom_product' ? 'custom_product' : (($item['item_kind'] ?? null) === 'custom_service' ? 'service' : null),
                    serviceId: $item['service_id'] ?? null,
                    lensOptionId: $item['lens_option_id'] ?? null,
                );

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => self::formatMoney($unitPriceInCents),
                    'amount' => self::formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'lens_option_id' => $item['lens_option_id'] ?? null,
                    'service_id' => $hasProductReference ? null : ($item['service_id'] ?? null),
                    'item_kind' => $snapshotResult['item_kind'],
                    'item_snapshot' => $snapshotResult['item_snapshot'],
                    'amount_in_cents' => $amountInCents,
                ];
            });

            app(ValidateOpticalQuotation::class)->handle(
                items: $itemSnapshots->map(fn (array $item): array => [
                    'item_kind' => $item['item_kind'],
                    'product_variant_id' => $item['product_variant_id'],
                ])->values(),
                requirePrescription: false,
            );

            $subtotalInCents = $itemSnapshots->sum('amount_in_cents');
            $discountInCents = (int) round(((float) ($validated['discount_amount'] ?? 0)) * 100);

            // Only admin can apply a nonzero discount
            if ($discountInCents > 0 && $editor !== null && ! $editor->isAdmin()) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Only an admin can apply a discount.'],
                ]);
            }

            if ($discountInCents > $subtotalInCents) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['The discount cannot exceed the quotation subtotal.'],
                ]);
            }

            $totalInCents = $subtotalInCents - $discountInCents;

            $updateData = [
                'subtotal' => self::formatMoney($subtotalInCents),
                'discount_amount' => self::formatMoney($discountInCents),
                'total' => self::formatMoney($totalInCents),
                'valid_until' => array_key_exists('valid_until', $validated) ? $validated['valid_until'] : $quotation->valid_until,
                'notes' => self::nullableTrimmed(array_key_exists('notes', $validated) ? $validated['notes'] : $quotation->notes),
                'internal_notes' => self::nullableTrimmed(array_key_exists('internal_notes', $validated) ? $validated['internal_notes'] : $quotation->internal_notes),
            ];

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
            'items.*.item_kind' => ['nullable', Rule::in(['catalog', 'lens', 'lens_option', 'service', 'custom_product', 'custom_service'])],
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
            'items.*.lens_option_id' => [
                'nullable',
                'integer',
                Rule::exists('lens_options', 'id')->where('is_active', true),
            ],
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('is_active', true)],
        ]);

        $validator->after(function ($validator) use ($data): void {
            foreach ($data['items'] ?? [] as $index => $item) {
                $references = collect([
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'lens_option_id' => $item['lens_option_id'] ?? null,
                    'service_id' => $item['service_id'] ?? null,
                ])->filter(fn (mixed $reference): bool => filled($reference));

                if ($references->count() > 1) {
                    $validator->errors()->add(
                        "items.{$index}.item_kind",
                        'A quotation item can reference only one catalog entry.',
                    );
                }

                if (filled($item['lens_option_id'] ?? null)
                    && filled($item['item_kind'] ?? null)
                    && $item['item_kind'] !== 'lens_option') {
                    $validator->errors()->add(
                        "items.{$index}.lens_option_id",
                        'A lens option must use the Lens Option item type.',
                    );
                }

                if (($item['item_kind'] ?? null) === 'lens_option' && blank($item['lens_option_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.lens_option_id",
                        'A Lens Option item requires a catalog lens option.',
                    );
                }
            }

            $optionIds = collect($data['items'] ?? [])
                ->pluck('lens_option_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id);

            foreach ($optionIds->duplicates()->unique() as $duplicateOptionId) {
                $validator->errors()->add(
                    'items',
                    "Lens option {$duplicateOptionId} may be selected only once per quotation.",
                );
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
