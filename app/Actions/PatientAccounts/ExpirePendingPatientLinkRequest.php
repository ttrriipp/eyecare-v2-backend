<?php

namespace App\Actions\PatientAccounts;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\PatientLinkRequest;
use App\Models\User;

class ExpirePendingPatientLinkRequest
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
    ) {}

    public function handle(User $account, string $reason): ?PatientLinkRequest
    {
        $linkRequest = $account->linkRequests()
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if ($linkRequest === null) {
            return null;
        }

        $linkRequest->update(['status' => 'expired']);

        $this->createAuditLog->handle(
            subject: $linkRequest,
            action: AuditEvent::PatientLinkRequestExpired,
            metadata: [
                'account_id' => $account->id,
                'reason' => $reason,
            ],
            actorId: $account->id,
        );

        return $linkRequest;
    }
}
