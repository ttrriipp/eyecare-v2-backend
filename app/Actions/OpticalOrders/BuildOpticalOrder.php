<?php

namespace App\Actions\OpticalOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\Notifications\NotifyPatientAccount;
use App\Enums\AuditEvent;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildOpticalOrder
{
    public function __construct(private readonly NotifyPatientAccount $notifyPatientAccount) {}

    /**
     * Create a JobOrder, snapshot items, and commit inventory.
     *
     * This is the shared core used by both order-creation paths.
     * It does not handle billing — callers append charges after this returns.
     *
     * @param  Collection<int, array<string, mixed>>  $items  Prepared item snapshots
     */
    public function handle(
        int $patientId,
        ?int $encounterId,
        ?int $prescriptionId,
        string $fulfillmentMode,
        bool $usesExternalSupplier,
        Collection $items,
        ?int $dispensedBy = null,
        ?int $actorId = null,
        bool $notifyPatient = true,
        ?string $externalSupplierName = null,
    ): JobOrder {
        $this->validateSupplierItems($patientId, $usesExternalSupplier, $fulfillmentMode, $prescriptionId, $items);

        return DB::transaction(function () use ($patientId, $encounterId, $prescriptionId, $fulfillmentMode, $usesExternalSupplier, $externalSupplierName, $items, $dispensedBy, $actorId, $notifyPatient): JobOrder {
            $patient = Patient::query()->findOrFail($patientId);

            $order = JobOrder::create([
                'patient_id' => $patientId,
                'encounter_id' => $encounterId,
                'prescription_id' => $prescriptionId,
                'status' => JobOrderStatus::Queued,
                'fulfillment_mode' => $fulfillmentMode,
                'uses_external_supplier' => $usesExternalSupplier,
                'external_supplier_name' => $usesExternalSupplier && filled($externalSupplierName)
                    ? trim($externalSupplierName)
                    : null,
                'total_amount' => $items->sum('amount'),
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            app(CommitJobOrderInventory::class)->handle(
                jobOrder: $order,
                actorId: $actorId ?? $dispensedBy,
            );

            app(CreateAuditLog::class)->handle(
                subject: $order,
                action: AuditEvent::JobOrderCreated,
                metadata: [
                    'patient_id' => $patientId,
                    'encounter_id' => $encounterId,
                    'prescription_id' => $prescriptionId,
                    'item_count' => $items->count(),
                    'fulfillment_mode' => $fulfillmentMode,
                    'uses_external_supplier' => $usesExternalSupplier,
                ],
                actorId: $actorId ?? $dispensedBy ?? auth()->id(),
            );

            if ($fulfillmentMode === 'immediate') {
                $order->update([
                    'status' => JobOrderStatus::Dispensed,
                    'started_at' => now(),
                    'dispensed_at' => now(),
                ]);

                if ($items->isNotEmpty()) {
                    DispensingEvent::create([
                        'job_order_id' => $order->id,
                        'dispensed_by' => $dispensedBy ?? auth()->id(),
                        'recipient_name' => $patient->full_name,
                        'notes' => 'Immediate fulfillment',
                    ]);
                }

                app(CreateAuditLog::class)->handle(
                    subject: $order,
                    action: AuditEvent::JobOrderStatusChanged,
                    metadata: [
                        'from' => JobOrderStatus::Queued->value,
                        'to' => JobOrderStatus::Dispensed->value,
                    ],
                    actorId: $actorId ?? $dispensedBy ?? auth()->id(),
                );
            }

            if ($notifyPatient && $fulfillmentMode === 'immediate') {
                $this->notifyPatientAccount->opticalOrderReleased($order);
            } elseif ($notifyPatient) {
                $this->notifyPatientAccount->opticalOrderConfirmed($order);
            }

            return $order->fresh();
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function validateSupplierItems(int $patientId, bool $usesExternalSupplier, string $fulfillmentMode, ?int $prescriptionId, Collection $items): void
    {
        if ($usesExternalSupplier && $fulfillmentMode !== 'prepared') {
            throw ValidationException::withMessages([
                'fulfillment_mode' => ['External supplier orders must use prepared fulfillment.'],
            ]);
        }

        $hasLensPackage = $items->contains(fn (array $item): bool => filled($item['lens_category_id'] ?? null));
        $hasPatientFrame = $items->contains(fn (array $item): bool => ($item['item_snapshot']['source'] ?? null) === 'patient');
        $prescription = $hasPatientFrame && $prescriptionId !== null
            ? Prescription::query()->find($prescriptionId)
            : null;
        $hasPatientPrescription = $prescription !== null
            && $prescription->patient_id === $patientId
            && $prescription->isCurrentVersion()
            && ! $prescription->isCancelled();

        foreach ($items as $item) {
            $hasCatalogProduct = filled($item['product_variant_id'] ?? null);
            $hasLensReference = filled($item['lens_category_id'] ?? null)
                || filled($item['lens_option_id'] ?? null);
            $isPatientFrame = ($item['item_kind'] ?? null) === CommercialItemKind::CustomProduct
                && ($item['item_snapshot']['source'] ?? null) === 'patient'
                && ($item['item_snapshot']['eyewear_role'] ?? 'frame') === 'frame'
                && ! $hasCatalogProduct
                && ! $hasLensReference
                && $hasLensPackage
                && $hasPatientPrescription
                && (int) ($item['quantity'] ?? 0) === 1;

            if ($usesExternalSupplier && $hasCatalogProduct) {
                throw ValidationException::withMessages([
                    'items' => ['External supplier orders cannot include catalog products.'],
                ]);
            }

            if (($item['item_snapshot']['source'] ?? null) === 'patient' && ! $isPatientFrame) {
                throw ValidationException::withMessages([
                    'items' => ['A patient-supplied frame requires one frame, a lens package, and a current prescription belonging to the patient.'],
                ]);
            }

            if (! $usesExternalSupplier && ! $hasCatalogProduct && ! $hasLensReference && ! $isPatientFrame) {
                throw ValidationException::withMessages([
                    'items' => ['Internal orders cannot include external products.'],
                ]);
            }
        }
    }
}
