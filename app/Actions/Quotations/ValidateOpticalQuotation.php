<?php

namespace App\Actions\Quotations;

use App\Enums\CommercialItemKind;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateOpticalQuotation
{
    /**
     * Validate the optical item matrix for a quotation.
     *
     * Enforces the approved single-build structure:
     * - Corrective eyewear requires exactly one lens_package
     * - At most one frame (catalog or patient-supplied)
     * - Lens options only when a lens_package exists
     * - Service-only, non-corrective Product-only, and mixed remain valid
     * - Corrective eyewear requires a current Patient-owned Prescription
     *
     * @param  Collection<int, array{item_kind: CommercialItemKind, ...}>  $items
     * @return array{is_corrective: bool, has_frame: bool, has_lens_package: bool}
     */
    public function handle(
        Collection $items,
        ?Patient $patient = null,
        ?Prescription $prescription = null,
        bool $requirePrescription = true,
    ): array {
        $lensPackages = $items->where('item_kind', CommercialItemKind::LensPackage);
        $frames = $items->whereIn('item_kind', [CommercialItemKind::Frame, CommercialItemKind::CustomProduct])
            ->filter(fn (array $item): bool => filled($item['product_variant_id'] ?? null));
        $lensOptions = $items->where('item_kind', CommercialItemKind::LensOption);

        $isCorrective = $lensPackages->isNotEmpty();

        foreach ($frames as $frame) {
            if ((int) ($frame['quantity'] ?? 1) !== 1) {
                throw ValidationException::withMessages([
                    'items' => ['Frame quantity must be 1.'],
                ]);
            }
        }

        foreach ($lensPackages as $lensPackage) {
            if ((int) ($lensPackage['quantity'] ?? 1) !== 1) {
                throw ValidationException::withMessages([
                    'items' => ['Lens package quantity must be 1 pair.'],
                ]);
            }
        }

        foreach ($lensOptions as $lensOption) {
            if ((int) ($lensOption['quantity'] ?? 1) !== 1) {
                throw ValidationException::withMessages([
                    'items' => ['Lens option quantity must be 1 pair.'],
                ]);
            }
        }

        $lensOptionIds = $lensOptions
            ->pluck('lens_option_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);

        if ($lensOptionIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Each lens option may be selected only once.'],
            ]);
        }

        // Exactly one lens package for corrective eyewear
        if ($lensPackages->count() > 1) {
            throw ValidationException::withMessages([
                'items' => ['A corrective-eyewear quotation must have exactly one lens package.'],
            ]);
        }

        // At most one frame
        if ($frames->count() > 1) {
            throw ValidationException::withMessages([
                'items' => ['A corrective-eyewear quotation must have at most one frame.'],
            ]);
        }

        // Lens options require a lens package
        if ($lensOptions->isNotEmpty() && $lensPackages->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Lens options require a lens package in the same quotation.'],
            ]);
        }

        // Corrective eyewear requires a current Patient-owned Prescription
        if ($isCorrective && $requirePrescription) {
            $this->validatePrescription($patient, $prescription);
        }

        return [
            'is_corrective' => $isCorrective,
            'has_frame' => $frames->isNotEmpty(),
            'has_lens_package' => $lensPackages->isNotEmpty(),
        ];
    }

    private function validatePrescription(?Patient $patient, ?Prescription $prescription): void
    {
        if ($prescription === null) {
            throw ValidationException::withMessages([
                'prescription' => ['A current prescription is required for corrective eyewear.'],
            ]);
        }

        if ($patient === null || $prescription->patient_id !== $patient->id) {
            throw ValidationException::withMessages([
                'prescription' => ['The prescription must belong to this patient.'],
            ]);
        }

        if (! $prescription->isCurrentVersion()) {
            throw ValidationException::withMessages([
                'prescription' => ['The prescription has been superseded. Use the current version.'],
            ]);
        }

        if ($prescription->isVoided()) {
            throw ValidationException::withMessages([
                'prescription' => ['This prescription has been voided and cannot be dispensed against.'],
            ]);
        }
    }
}
