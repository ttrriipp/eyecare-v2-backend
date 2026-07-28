<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Enums\QuotationStatus;
use App\Models\Encounter;
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
    public function handle(Encounter $encounter, User $creator, array $data): Quotation
    {
        if (! in_array($creator->role->name, ['admin', 'staff'], true)) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create a quotation.'],
            ]);
        }

        $validated = $this->validate($data);

        return DB::transaction(function () use ($encounter, $creator, $validated): Quotation {
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

            $prescription = $lockedEncounter->prescriptions()
                ->whereDoesntHave('nextPrescription')
                ->latest('id')
                ->first();

            if ($prescription === null) {
                throw ValidationException::withMessages([
                    'prescription' => ['Finalize a current prescription before creating a quotation.'],
                ]);
            }

            $itemSnapshots = collect($validated['items'])->map(function (array $item): array {
                $unitPriceInCents = (int) round(((float) $item['unit_price']) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                return [
                    'description' => trim($item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $this->formatMoney($unitPriceInCents),
                    'amount' => $this->formatMoney($amountInCents),
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'lens_category_id' => $item['lens_category_id'] ?? null,
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

            $quotation = Quotation::query()->create([
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->id,
                'prescription_id' => $prescription->id,
                'status' => QuotationStatus::Draft,
                'valid_until' => $validated['valid_until'] ?? null,
                'notes' => $this->nullableTrimmed($validated['notes'] ?? null),
                'internal_notes' => $this->nullableTrimmed($validated['internal_notes'] ?? null),
            ]);

            $revision = $quotation->revisions()->create([
                'revision_number' => 1,
                'subtotal' => $this->formatMoney($subtotalInCents),
                'discount_amount' => $this->formatMoney($discountInCents),
                'total' => $this->formatMoney($subtotalInCents - $discountInCents),
            ]);

            $revision->items()->createMany(
                $itemSnapshots
                    ->map(fn (array $item): array => collect($item)->except('amount_in_cents')->all())
                    ->all(),
            );

            $this->createAuditLog->handle(
                subject: $quotation,
                action: AuditEvent::QuotationCreated,
                metadata: [
                    'encounter_id' => $lockedEncounter->id,
                    'prescription_id' => $prescription->id,
                    'quotation_revision_id' => $revision->id,
                    'item_count' => $itemSnapshots->count(),
                    'total' => $this->formatMoney($subtotalInCents - $discountInCents),
                ],
                actorId: $creator->id,
            );

            return $quotation->load('latestRevision.items');
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
