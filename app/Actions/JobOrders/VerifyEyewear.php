<?php

namespace App\Actions\JobOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\JobOrderEyewearSpecification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VerifyEyewear
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Verify completed eyewear against the approved specification.
     *
     * Only an approved in-Processing corrective order can be verified.
     * Records who checked the finished eyewear, when, and optional notes.
     */
    public function handle(
        JobOrder $jobOrder,
        User $verifier,
        ?string $notes = null,
    ): JobOrderEyewearSpecification {
        if ($jobOrder->status !== JobOrderStatus::InProgress) {
            throw ValidationException::withMessages([
                'job_order' => ['Only in-processing orders can be verified.'],
            ]);
        }

        return DB::transaction(function () use ($jobOrder, $verifier, $notes): JobOrderEyewearSpecification {
            $specification = $jobOrder->eyewearSpecification;

            if ($specification === null) {
                throw ValidationException::withMessages([
                    'job_order' => ['No eyewear specification found.'],
                ]);
            }

            if (! $specification->isApproved()) {
                throw ValidationException::withMessages([
                    'specification' => ['The specification must be approved before verification.'],
                ]);
            }

            $locked = JobOrderEyewearSpecification::query()
                ->lockForUpdate()
                ->findOrFail($specification->id);

            // Idempotent: don't create duplicate verification
            if ($locked->isVerified()) {
                return $locked;
            }

            $locked->update([
                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'verification_notes' => $notes,
            ]);

            $this->createAuditLog->handle(
                subject: $jobOrder,
                action: 'eyewear_specification.verified',
                metadata: [
                    'specification_id' => $locked->id,
                    'verified_by' => $verifier->id,
                ],
                actorId: $verifier->id,
            );

            return $locked->fresh();
        });
    }
}
