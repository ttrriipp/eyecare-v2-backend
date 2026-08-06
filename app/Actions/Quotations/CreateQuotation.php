<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
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
    ): Quotation {
        if (! in_array($creator->role->name, ['admin', 'staff'], true)) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create a quotation.'],
            ]);
        }

        $validated = $this->validate($data, $encounter);

        return DB::transaction(function () use ($patient, $creator, $validated, $encounter, $prescription): Quotation {
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
            $hasCorrectiveItems = collect($validated['items'])->contains(
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
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                // Derive item type. Catalog/lens/service items are typed by which
                // reference is filled; a custom item has no reference to key off,
                // so it relies on the form's explicit item_type intent instead.
                $hasProductReference = filled($item['product_variant_id'] ?? null) || filled($item['lens_category_id'] ?? null);
                $itemType = match (true) {
                    $hasProductReference => TransactionItemType::Product,
                    filled($item['service_id'] ?? null) => TransactionItemType::Service,
                    ($item['item_type'] ?? null) === 'custom_product' => TransactionItemType::Product,
                    default => TransactionItemType::Service,
                };

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $this->formatMoney($unitPriceInCents),
                    'amount' => $this->formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
                    'service_id' => $hasProductReference ? null : ($item['service_id'] ?? null),
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
                    ->map(fn (array $item): array => collect($item)->except('amount_in_cents')->all())
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
            'items.*.item_type' => ['nullable', Rule::in(['catalog', 'lens', 'service', 'custom_product', 'custom_service'])],
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
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('is_active', true)],
        ]);

        $validator->after(function ($validator) use ($data): void {
            foreach ($data['items'] ?? [] as $index => $item) {
                if (filled($item['product_variant_id'] ?? null) && filled($item['lens_category_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.product_variant_id",
                        'A quotation item can reference either a catalog item or a lens category, not both.',
                    );
                }

                $hasCatalogReference = filled($item['product_variant_id'] ?? null) || filled($item['lens_category_id'] ?? null);

                if ($hasCatalogReference && filled($item['service_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.service_id",
                        'An item cannot reference both a service and a catalog item or lens category.',
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

    private function formatMoney(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return trim($value);
    }
}
