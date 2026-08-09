<?php

namespace App\Actions\JobOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Models\JobOrderEyewearSpecification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveEyewearSpecification
{
    public function __construct(private CreateAuditLog $createAuditLog) {}

    /**
     * Approve a corrective-eyewear specification.
     *
     * Only an active optometrist may approve. Approval is tied to the
     * exact saved specification state.
     */
    public function handle(
        JobOrderEyewearSpecification $specification,
        User $approver,
    ): JobOrderEyewearSpecification {
        if (! $approver->isOptometrist()) {
            throw ValidationException::withMessages([
                'approver' => ['Only an active optometrist may approve corrective eyewear.'],
            ]);
        }

        return DB::transaction(function () use ($specification, $approver): JobOrderEyewearSpecification {
            $locked = JobOrderEyewearSpecification::query()
                ->lockForUpdate()
                ->findOrFail($specification->id);

            // Validate specification has required data
            if (blank($locked->lens_design_snapshot)) {
                throw ValidationException::withMessages([
                    'specification' => ['Complete the lens design before approving.'],
                ]);
            }

            $locked->update([
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $this->createAuditLog->handle(
                subject: $locked->jobOrder,
                action: 'eyewear_specification.approved',
                metadata: [
                    'specification_id' => $locked->id,
                    'approved_by' => $approver->id,
                ],
                actorId: $approver->id,
            );

            return $locked->fresh();
        });
    }
}
