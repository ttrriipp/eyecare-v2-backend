<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Patient;

class PatientObserver
{
    /**
     * Handle the Patient "created" event.
     */
    public function created(Patient $patient): void
    {
        //
    }

    /**
     * Handle the Patient "updated" event.
     */
    public function updated(Patient $patient): void
    {
        $profileFields = [
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'occupation',
            'address',
            'gender',
            'contact_email',
            'phone',
        ];
        $changedFields = array_values(array_intersect(array_keys($patient->getChanges()), $profileFields));

        if ($changedFields === []) {
            return;
        }

        app(CreateAuditLog::class)->handle(
            subject: $patient,
            action: AuditEvent::PatientUpdated,
            metadata: [
                'changed_fields' => $changedFields,
            ],
            actorId: auth()->id(),
        );
    }
}
