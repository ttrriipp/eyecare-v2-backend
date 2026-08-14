<?php

namespace App\Actions\Privacy;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\IncidentStatus;
use App\Models\PrivacyIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdatePrivacyIncident
{
    public function __construct(private readonly CreateAuditLog $createAuditLog) {}

    /**
     * Update a privacy incident with new information.
     */
    public function handle(
        PrivacyIncident $incident,
        User $handler,
        ?string $status = null,
        ?string $containmentActions = null,
        ?string $decisions = null,
        ?string $resolutionNotes = null,
        ?int $assignedTo = null,
    ): PrivacyIncident {
        return DB::transaction(function () use ($incident, $handler, $status, $containmentActions, $decisions, $resolutionNotes, $assignedTo): PrivacyIncident {
            $previousStatus = $incident->status?->value;
            $attributes = [];

            if ($status !== null) {
                $newStatus = IncidentStatus::from($status);
                $attributes['status'] = $newStatus;

                match ($newStatus) {
                    IncidentStatus::Contained => $attributes['contained_at'] = now(),
                    IncidentStatus::Resolved => $attributes['resolved_at'] = now(),
                    IncidentStatus::Closed => $attributes['closed_at'] = now(),
                    default => null,
                };
            }

            if ($containmentActions !== null) {
                $attributes['containment_actions'] = $containmentActions;
            }

            if ($decisions !== null) {
                $attributes['decisions'] = $decisions;
            }

            if ($resolutionNotes !== null) {
                $attributes['resolution_notes'] = $resolutionNotes;
            }

            if ($assignedTo !== null) {
                $attributes['assigned_to'] = $assignedTo;
            }

            $incident->update($attributes);

            $this->createAuditLog->handle(
                subject: $incident,
                action: AuditEvent::PrivacyIncidentUpdated,
                metadata: [
                    'previous_status' => $previousStatus,
                    'status' => $incident->status?->value,
                    'assigned_to' => $incident->assigned_to,
                    'changed_fields' => array_keys($attributes),
                ],
                actorId: $handler->id,
            );

            return $incident->fresh();
        });
    }
}
