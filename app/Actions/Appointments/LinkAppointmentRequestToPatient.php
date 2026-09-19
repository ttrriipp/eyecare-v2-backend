<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Conversations\AssociateAccountConversation;
use App\Actions\PatientAccounts\LinkPatientAccount;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
use App\Actions\PatientAccounts\PatientLinkIdentitySnapshot;
use App\Enums\AuditEvent;
use App\Exceptions\PatientIdentityMismatchException;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkAppointmentRequestToPatient
{
    public function __construct(
        private readonly CreateAuditLog $createAuditLog,
        private readonly AssociateAccountConversation $associateAccountConversation,
        private readonly LinkPatientAccount $linkPatientAccount,
        private readonly PatientAccountIdentityMatcher $identityMatcher,
        private readonly PatientLinkIdentitySnapshot $identitySnapshot,
    ) {}

    public function handle(AppointmentRequest $request, Patient $patient): AppointmentRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be linked to a patient.'],
            ]);
        }

        if ($request->patient_id !== null) {
            throw ValidationException::withMessages([
                'request' => ['This request is already linked to a patient.'],
            ]);
        }

        return DB::transaction(function () use ($request, $patient): AppointmentRequest {
            // Lock order: account -> workflow request -> Patient.
            $account = User::query()->lockForUpdate()->findOrFail($request->user_id);
            $lockedRequest = AppointmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if (! $lockedRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['Only pending appointment requests can be linked to a patient.'],
                ]);
            }

            if ($lockedRequest->patient_id !== null) {
                throw ValidationException::withMessages([
                    'request' => ['This request is already linked to a patient.'],
                ]);
            }

            if ($lockedPatient->user_id !== null && $lockedPatient->user_id !== $account->id) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient is already linked to a different account.'],
                ]);
            }

            $existingLink = $account->patient;
            $wasUnlinked = $existingLink === null;

            if ($existingLink !== null && $existingLink->id !== $lockedPatient->id) {
                throw ValidationException::withMessages([
                    'patient' => ['This account is already linked to a different patient record.'],
                ]);
            }

            if ($wasUnlinked) {
                $snapshot = $lockedRequest->encrypted_identity_snapshot;

                if (! is_array($snapshot)
                    || ! $this->identitySnapshot->matchesAccount($snapshot, $account)
                    || ! $this->identityMatcher->handleSnapshot($snapshot, $lockedPatient)->isEligible()) {
                    throw ValidationException::withMessages([
                        'patient' => ['The account details do not match this patient record.'],
                    ]);
                }

                try {
                    $this->linkPatientAccount->handle(
                        account: $account,
                        patient: $lockedPatient,
                        source: 'appointment_request',
                        sourceId: $lockedRequest->id,
                        actorId: auth()->id(),
                    );
                } catch (PatientIdentityMismatchException) {
                    throw ValidationException::withMessages([
                        'patient' => ['The account details do not match this patient record.'],
                    ]);
                }
            } else {
                $this->associateAccountConversation->handle($account, $lockedPatient);
            }

            $lockedRequest->update(['patient_id' => $lockedPatient->id]);

            $this->createAuditLog->handle(
                subject: $lockedRequest,
                action: AuditEvent::AppointmentRequestLinked,
                metadata: [
                    'patient_id' => $lockedPatient->id,
                    'account_id' => $account->id,
                    'account_link_created' => $wasUnlinked,
                ],
                actorId: auth()->id(),
            );

            return $lockedRequest->fresh();
        });
    }
}
