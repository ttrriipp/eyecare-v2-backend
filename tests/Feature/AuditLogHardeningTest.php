<?php

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('audit logs honor explicit actors and keep metadata safe for storage', function () {
    $authenticatedUser = User::factory()->staff()->create();
    $workflowActor = User::factory()->staff()->create();
    $subject = User::factory()->staff()->create();

    $userAgent = str_repeat('a', 300);

    $this->actingAs($authenticatedUser);

    $auditLog = app(CreateAuditLog::class)->handle(
        subject: $subject,
        action: AuditEvent::UserRoleChanged,
        metadata: [
            'patient_id' => 42,
            'reason' => 'emergency',
            'notes' => 'A private narrative that should not be copied into the audit trail.',
            'chief_complaint' => 'A clinical narrative.',
        ],
        actorId: $workflowActor->id,
        userAgent: $userAgent,
    );

    expect($auditLog->actor_id)->toBe($workflowActor->id)
        ->and(strlen((string) $auditLog->user_agent))->toBe(255)
        ->and($auditLog->metadata)->toMatchArray([
            'patient_id' => 42,
            'reason' => 'emergency',
        ])
        ->and($auditLog->metadata)->not->toHaveKey('notes')
        ->and($auditLog->metadata)->not->toHaveKey('chief_complaint');
});

test('audit logs cannot be updated or deleted programmatically', function () {
    $auditLog = AuditLog::factory()->create();

    expect(fn () => $auditLog->update(['action' => 'tampered']))
        ->toThrow(LogicException::class);

    expect(fn () => $auditLog->delete())
        ->toThrow(LogicException::class);

    expect(fn () => AuditLog::query()->whereKey($auditLog->id)->update(['action' => 'tampered']))
        ->toThrow(LogicException::class);

    expect(fn () => AuditLog::query()->whereKey($auditLog->id)->delete())
        ->toThrow(LogicException::class);
});
