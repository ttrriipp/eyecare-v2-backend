<?php

namespace App\Actions\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateAuditLog
{
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
            'metadata' => $metadata,
            'ip_address' => $ipAddress ?? (app()->bound('request') ? request()?->ip() : null),
            'user_agent' => $userAgent ?? (app()->bound('request') ? request()?->userAgent() : null),
        ]);
    }
}
