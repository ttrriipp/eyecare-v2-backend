<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Enums\QuotationStatus;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateQuotation
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Patient $patient,
        User $creator,
        array $data,
        ?Encounter $encounter = null,
        ?Prescription $prescription = null,
        bool $includePrescriptionEyewear = false,
    ): Quotation {
        if (! $creator->hasPanelRole()) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create a quotation.'],
            ]);
        }

        $validated = $this->validate($data, $encounter);

        if ($includePrescriptionEyewear) {
            $this->validatePrescriptionEyewearMode($validated['items']);
        }

        return DB::transaction(function () use ($patient, $creator, $validated, $encounter, $prescription, $includePrescriptionEyewear): Quotation {
            // Validate encounter if provided
            if ($encounter !== null) {
                $lockedEncounter = Encounter::query()
                    ->lockForUpdate()
                    ->findOrFail($encounter->id);

                if (! in_array($lockedEncounter->status, [EncounterStatus::InProgress, EncounterStatus::Completed], true)) {
                    throw ValidationException::withMessages([
                        'encounter' => ['A quotation requires an in-progress or completed encounter.'],
                    ]);
                }

                if (Quotation::query()->withTrashed()->where('encounter_id', $lockedEncounter->id)->exists()) {
                    throw ValidationException::withMessages([
                        'encounter' => ['This encounter already has a quotation.'],
                    ]);
                }
            }

            // Resolve prescription if corrective eyewear is included. An explicitly
            // passed prescription (an existing Rx, no new encounter) takes priority
            // over resolving one from the encounter (a same-visit quotation).
            $prescriptionId = null;
            $hasCorrectiveItems = $includePrescriptionEyewear || collect($validated['items'])->contains(
                fn (array $item): bool => filled($item['lens_category_id'] ?? null),
            );

            if ($hasCorrectiveItems) {
                if ($prescription !== null) {
                    if ($prescription->patient_id !== $patient->id || ! $prescription->isCurrentVersion()) {
                        throw ValidationException::withMessages([
                            'prescription' => ['The selected prescription is not this patient\'s current prescription.'],
                        ]);
                    }

                    $prescriptionId = $prescription->id;
                } elseif ($encounter !== null) {
                    $resolved = $encounter->prescriptions()
                        ->whereDoesntHave('nextPrescription')
                        ->latest('id')
                        ->first();

                    if ($resolved === null) {
                        throw ValidationException::withMessages([
                            'prescription' => ['Finalize a current prescription before creating corrective eyewear.'],
                        ]);
                    }

                    $prescriptionId = $resolved->id;
                } else {
                    throw ValidationException::withMessages([
                        'encounter' => ['An encounter or an existing prescription is required when the order includes corrective eyewear.'],
                    ]);
                }
            }

            $itemSnapshots = collect($validated['items'])->map(function (array $item): array {
                $item = $this->applyCatalogValues($item);
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
                    'description' => trim((string) ($item['description'] ?? '')),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $this->formatMoney($unitPriceInCents),
                    'amount' => $this->formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'lens_option_id' => $item['lens_option_id'] ?? null,
                    'service_id' => $hasProductReference ? null : ($item['service_id'] ?? null),
                    'item_kind' => $snapshotResult['item_kind'],
                    'item_snapshot' => $snapshotResult['item_snapshot'],
                    'eyewear_role' => $item['eyewear_role'] ?? null,
                    'amount_in_cents' => $amountInCents,
                ];
            });

            app(ValidateOpticalQuotation::class)->handle(
                items: $itemSnapshots->map(fn (array $item): array => [
                    'item_kind' => $item['item_kind'],
                    'product_variant_id' => $item['product_variant_id'],
                    'lens_option_id' => $item['lens_option_id'],
                    'quantity' => $item['quantity'],
                    'eyewear_role' => $item['eyewear_role'],
                ])->values(),
                patient: $patient,
                prescription: $prescriptionId !== null ? Prescription::query()->find($prescriptionId) : null,
            );

            $subtotalInCents = $itemSnapshots->sum('amount_in_cents');
            $discountInCents = (int) round(((float) ($validated['discount_amount'] ?? 0)) * 100);

            // Only admin can apply a nonzero discount
            if ($discountInCents > 0 && ! $creator->isAdmin()) {
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

            $quotation = Quotation::query()->create([
                'patient_id' => $patient->id,
                'encounter_id' => $encounter?->id,
                'prescription_id' => $prescriptionId,
                'status' => QuotationStatus::Draft,
                'valid_until' => $validated['valid_until'] ?? null,
                'subtotal' => $this->formatMoney($subtotalInCents),
                'discount_amount' => $this->formatMoney($discountInCents),
                'total' => $this->formatMoney($totalInCents),
                'notes' => $this->nullableTrimmed($validated['notes'] ?? null),
                'internal_notes' => $this->nullableTrimmed($validated['internal_notes'] ?? null),
            ]);

            // Create items directly on the quotation
            $quotation->items()->createMany(
                $itemSnapshots
                    ->map(fn (array $item): array => collect($item)
                        ->except(['amount_in_cents', 'eyewear_role'])
                        ->all())
                    ->all(),
            );

            $this->createAuditLog->handle(
                subject: $quotation,
                action: AuditEvent::QuotationCreated,
                metadata: [
                    'encounter_id' => $encounter?->id,
                    'prescription_id' => $prescriptionId,
                    'item_count' => $itemSnapshots->count(),
                    'total' => $this->formatMoney($totalInCents),
                ],
                actorId: $creator->id,
            );

            return $quotation->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Encounter $encounter): array
    {
        $validator = Validator::make($data, [
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'discount_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_kind' => ['nullable', Rule::in(['catalog', 'lens', 'lens_option', 'service', 'custom_product', 'custom_service'])],
            'items.*.eyewear_role' => ['nullable', Rule::in(['frame', 'lens_package', 'lens_option', 'other'])],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
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

                if ($references->isEmpty() && blank($item['description'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.description",
                        'A custom quotation item requires a description.',
                    );
                }

                if ($references->isEmpty() && blank($item['unit_price'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.unit_price",
                        'A custom quotation item requires a unit price.',
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

    private function formatMoney(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyCatalogValues(array $item): array
    {
        if (filled($item['product_variant_id'] ?? null)) {
            $variant = ProductVariant::query()
                ->with('product')
                ->findOrFail($item['product_variant_id']);

            return [
                ...$item,
                'description' => "{$variant->product->name} — {$variant->name}",
                'unit_price' => $variant->price,
            ];
        }

        if (filled($item['lens_category_id'] ?? null)) {
            $lensCategory = LensCategory::query()->findOrFail($item['lens_category_id']);

            return $this->applyNamedCatalogValues($item, $lensCategory->name, $lensCategory->price);
        }

        if (filled($item['lens_option_id'] ?? null)) {
            $lensOption = LensOption::query()->active()->findOrFail($item['lens_option_id']);

            return $this->applyNamedCatalogValues($item, $lensOption->name, $lensOption->price);
        }

        if (filled($item['service_id'] ?? null)) {
            $service = Service::query()->active()->findOrFail($item['service_id']);

            return $this->applyNamedCatalogValues($item, $service->name, $service->price);
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyNamedCatalogValues(array $item, string $name, mixed $price): array
    {
        if ($price === null) {
            throw ValidationException::withMessages([
                'items' => ["{$name} does not have a catalog price."],
            ]);
        }

        return [
            ...$item,
            'description' => $name,
            'unit_price' => $price,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validatePrescriptionEyewearMode(array $items): void
    {
        $items = collect($items);
        $hasFormRoles = $items->contains(fn (array $item): bool => array_key_exists('eyewear_role', $item));
        $lensPackageItems = $items->filter(fn (array $item): bool => $hasFormRoles
            ? ($item['eyewear_role'] ?? null) === 'lens_package'
            : ($item['item_kind'] ?? null) === 'lens');

        if ($lensPackageItems->count() !== 1
            || $lensPackageItems->contains(fn (array $item): bool => blank($item['lens_category_id'] ?? null))) {
            throw ValidationException::withMessages([
                'items' => ['Prescription eyewear requires exactly one lens package.'],
            ]);
        }

        $frameItems = $items->filter(fn (array $item): bool => $hasFormRoles
            ? ($item['eyewear_role'] ?? null) === 'frame'
            : in_array($item['item_kind'] ?? null, ['catalog', 'custom_product'], true));
        $catalogItems = $frameItems->filter(fn (array $item): bool => ($item['item_kind'] ?? null) === 'catalog');
        $catalogVariantIds = $catalogItems->pluck('product_variant_id')->filter()->map(fn (mixed $id): int => (int) $id);

        if ($catalogItems->count() !== $catalogVariantIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['Select a catalog frame for the prescription eyewear frame.'],
            ]);
        }

        $catalogFrames = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $catalogVariantIds)
            ->get()
            ->filter(fn (ProductVariant $variant): bool => $variant->product?->product_type === 'frame');

        if ($catalogFrames->count() !== $catalogVariantIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['Prescription eyewear catalog items must be frames.'],
            ]);
        }

        $patientFrameItems = $frameItems->filter(fn (array $item): bool => ($item['item_kind'] ?? null) === 'custom_product');

        foreach ($patientFrameItems as $item) {
            if ((int) ($item['quantity'] ?? 0) !== 1) {
                throw ValidationException::withMessages([
                    'items' => ['A patient-supplied frame quantity must be 1.'],
                ]);
            }
        }

        $frameCount = $catalogFrames->count() + $patientFrameItems->count();

        if ($frameCount > 1) {
            throw ValidationException::withMessages([
                'items' => ['Prescription eyewear may include at most one frame.'],
            ]);
        }
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return trim($value);
    }
}
