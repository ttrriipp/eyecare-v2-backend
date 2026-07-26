<?php

namespace App\Actions\Privacy;

use App\Enums\PrivacyRequestDisposition;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessPrivacyRequest
{
    /**
     * Process a privacy rights request.
     *
     * Clinical/financial history cannot be silently deleted.
     */
    public function handle(
        PrivacyRequest $request,
        string $disposition,
        User $handler,
        ?string $reason = null,
    ): PrivacyRequest {
        $validDispositions = [
            PrivacyRequestDisposition::Approved->value,
            PrivacyRequestDisposition::PartiallyApproved->value,
            PrivacyRequestDisposition::Denied->value,
            PrivacyRequestDisposition::RequiresReview->value,
        ];

        if (! in_array($disposition, $validDispositions, true)) {
            throw ValidationException::withMessages([
                'disposition' => ['Invalid disposition.'],
            ]);
        }

        return DB::transaction(function () use ($request, $disposition, $handler, $reason): PrivacyRequest {
            $request->update([
                'disposition' => $disposition,
                'disposition_reason' => $reason,
                'handled_by' => $handler->id,
                'handled_at' => now(),
            ]);

            // Note: Actual data erasure/correction is NOT performed here.
            // Clinical/financial history is retained per legal retention duties.
            // The request is recorded for audit purposes only.

            return $request->fresh();
        });
    }
}
