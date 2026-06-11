<?php

namespace App\Actions\Audit;

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
        string $action,
        ?array $metadata = null,
        ?int $actorId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
