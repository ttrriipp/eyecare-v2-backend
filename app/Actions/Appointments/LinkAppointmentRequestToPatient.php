<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Conversations\AssociateAccountConversation;
use App\Enums\AppointmentRequestStatus;
use App\Enums\AuditEvent;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkAppointmentRequestToPatient
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    public function handle(AppointmentRequest $request, Patient $patient): AppointmentRequest
    {
        if ($request->status !== AppointmentRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'request' => ['Only pending appointment requests can be linked to a patient.'],
            ]);
        }

        if ($request->patient_id !== null) {
            throw ValidationException::withMessages([
                'request' => ['This request is already linked to a patient.'],
            ]);
        }

        return DB::transaction(function () use ($request, $patient) {
            $account = $request->user;
            $wasUnlinked = $account->patient === null;
            $request->update(['patient_id' => $patient->id]);

            $this->linkAccountIfNeeded($request, $patient);

            $this->createAuditLog->handle(
                subject: $request,
                action: AuditEvent::AppointmentRequestLinked,
                metadata: [
                    'patient_id' => $patient->id,
                    'account_id' => $account->id,
                    'account_link_created' => $wasUnlinked,
                ],
                actorId: auth()->id(),
            );

            if ($wasUnlinked) {
                $this->createAuditLog->handle(
                    subject: $patient,
                    action: AuditEvent::PatientAccountLinked,
                    metadata: [
                        'account_id' => $account->id,
                        'appointment_request_id' => $request->id,
                    ],
                    actorId: auth()->id(),
                );
            }

            return $request->fresh();
        });
    }

    /**
     * Resolving a request to a patient means a staff member has already
     * verified this identity match — extend that same trust to the
     * requesting account, the same way ReviewPatientLinkRequest does for
     * the standalone account-linking flow. Without this, an approved
     * appointment request leaves the account looking unlinked, blocked
     * from every endpoint that requires an active patient link — including
     * viewing the very appointment it just requested.
     */
    private function linkAccountIfNeeded(AppointmentRequest $request, Patient $patient): void
    {
        $account = $request->user;
        $existingLink = $account->patient;

        if ($existingLink !== null) {
            if ($existingLink->id !== $patient->id) {
                throw ValidationException::withMessages([
                    'patient' => ['This account is already linked to a different patient record.'],
                ]);
            }

            app(AssociateAccountConversation::class)->handle($account, $patient);

            return;
        }

        $patient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

        if ($patient->user_id !== null && $patient->user_id !== $account->id) {
            throw ValidationException::withMessages([
                'patient' => ['This patient record is already linked to a different account.'],
            ]);
        }

        if ($patient->user_id === null) {
            $patient->update(['user_id' => $account->id]);
        }

        app(AssociateAccountConversation::class)->handle($account, $patient);
    }
}
