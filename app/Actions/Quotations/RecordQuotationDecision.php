<?php

namespace App\Actions\Quotations;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordQuotationDecision
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    /**
     * Accept or decline a draft quotation.
     *
     * - Draft → Accepted (with confirmed_by/confirmed_at)
     * - Draft → Declined (with decline_reason)
     */
    public function handle(
        Quotation $quotation,
        string $decision,
        User $recorder,
        ?string $reason = null,
    ): Quotation {
        $targetStatus = QuotationStatus::from($decision);

        if ($targetStatus === QuotationStatus::Declined) {
            if (blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required when declining a quotation.'],
                ]);
            }
        }

        return DB::transaction(function () use ($quotation, $targetStatus, $recorder, $reason): Quotation {
            $lockedQuotation = Quotation::query()
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if ($lockedQuotation->status !== QuotationStatus::Draft) {
                throw ValidationException::withMessages([
                    'quotation' => ['Only draft quotations can be accepted or declined.'],
                ]);
            }

            $attributes = ['status' => $targetStatus];

            if ($targetStatus === QuotationStatus::Accepted) {
                $attributes['confirmed_by'] = $recorder->id;
                $attributes['confirmed_at'] = Carbon::now();
            }

            if ($targetStatus === QuotationStatus::Declined) {
                $attributes['decline_reason'] = $reason;
            }

            $lockedQuotation->update($attributes);

            $this->createAuditLog->handle(
                subject: $lockedQuotation,
                action: $targetStatus === QuotationStatus::Accepted
                    ? AuditEvent::QuotationAccepted
                    : AuditEvent::QuotationDeclined,
                metadata: [
                    'previous_status' => QuotationStatus::Draft->value,
                    'status' => $targetStatus->value,
                    'reason_provided' => filled($reason),
                ],
                actorId: $recorder->id,
            );

            return $lockedQuotation->fresh();
        });
    }
}
