<?php

namespace App\Actions\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateAuditLog
{
    private const MAX_USER_AGENT_LENGTH = 255;

    /**
     * Metadata keys that can contain clinical or personal narratives rather
     * than the identifiers and state transitions needed for an audit trail.
     *
     * @var list<string>
     */
    private const SENSITIVE_METADATA_KEYS = [
        'assessment',
        'chief_complaint',
        'clinical_notes',
        'content',
        'description',
        'decision_note',
        'decline_reason',
        'findings',
        'notes',
        'narrative',
        'plan',
        'reason_details',
        'reschedule_reason',
        'reversal_reason',
        'resolution_notes',
        'staff_notes',
        'void_reason',
        'containment_actions',
        'decisions',
        'balance_override_reason',
    ];

    /**
     * Persist an audit log entry for a workflow action.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function handle(
        Model $subject,
        AuditEvent|string $action,
        ?array $metadata = null,
        ?int $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action instanceof AuditEvent ? $action->value : $action,
            'metadata' => $this->sanitizeMetadata($metadata),
            'ip_address' => $ipAddress ?? (app()->bound('request') ? request()?->ip() : null),
            'user_agent' => $this->truncateUserAgent(
                $userAgent ?? (app()->bound('request') ? request()?->userAgent() : null),
            ),
        ]);
    }

    /**
     * Remove narrative fields and bound values before writing JSON metadata.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $sanitized = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_METADATA_KEYS, true)) {
                continue;
            }

            // A bare reason is retained only when it is an enum-like category;
            // free-text reasons belong in the source record, not the audit log.
            if ($normalizedKey === 'reason'
                && (! is_string($value) || ! preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $value))) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeMetadataValue($value);
        }

        return $sanitized;
    }

    private function sanitizeMetadataValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $nested = [];

            foreach ($value as $key => $nestedValue) {
                $normalizedKey = strtolower((string) $key);

                if (in_array($normalizedKey, self::SENSITIVE_METADATA_KEYS, true)) {
                    continue;
                }

                if ($normalizedKey === 'reason'
                    && (! is_string($nestedValue) || ! preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $nestedValue))) {
                    continue;
                }

                $nested[(string) $key] = $this->sanitizeMetadataValue($nestedValue);
            }

            return $nested;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 255);
        }

        return $value;
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null
            ? null
            : mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
    }
}
