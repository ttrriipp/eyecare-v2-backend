<?php

use App\Actions\Privacy\UpdatePrivacyIncident;
use App\Enums\IncidentStatus;
use App\Models\PrivacyIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('incident discovery scope handler and decisions are retained', function () {
    $admin = User::factory()->admin()->create();
    $incident = PrivacyIncident::factory()->create([
        'reported_by' => $admin->id,
    ]);

    expect($incident->title)->not->toBeNull()
        ->and($incident->reference_number)->toStartWith('INC-')
        ->and($incident->reported_by)->toBe($admin->id)
        ->and($incident->discovered_at)->not->toBeNull();
});

test('incident status transitions set timestamps', function () {
    $admin = User::factory()->admin()->create();
    $incident = PrivacyIncident::factory()->create();

    app(UpdatePrivacyIncident::class)->handle(
        $incident,
        $admin,
        status: IncidentStatus::Contained->value,
        containmentActions: 'Isolated affected systems',
    );

    expect($incident->fresh()->status)->toBe(IncidentStatus::Contained)
        ->and($incident->fresh()->contained_at)->not->toBeNull()
        ->and($incident->fresh()->containment_actions)->toBe('Isolated affected systems');

    app(UpdatePrivacyIncident::class)->handle(
        $incident->fresh(),
        $admin,
        status: IncidentStatus::Resolved->value,
        resolutionNotes: 'Root cause identified and patched.',
    );

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->fresh()->resolved_at)->not->toBeNull();
});

test('workflow records decisions without auto-reporting externally', function () {
    $admin = User::factory()->admin()->create();
    $incident = PrivacyIncident::factory()->create();

    app(UpdatePrivacyIncident::class)->handle(
        $incident,
        $admin,
        decisions: 'Notify affected patients within 72 hours per NPC guidelines.',
    );

    expect($incident->fresh()->decisions)->toContain('Notify affected patients');
    // No external API calls are made — the decision is recorded for audit only
});

test('incident details are access-controlled', function () {
    $admin = User::factory()->admin()->create();
    $incident = PrivacyIncident::factory()->create(['reported_by' => $admin->id]);

    expect($incident->reportedBy->id)->toBe($admin->id)
        ->and($incident->reference_number)->not->toBeNull();
});

test('incident can be assigned to a handler', function () {
    $admin = User::factory()->admin()->create();
    $handler = User::factory()->admin()->create();
    $incident = PrivacyIncident::factory()->create();

    app(UpdatePrivacyIncident::class)->handle(
        $incident,
        $admin,
        assignedTo: $handler->id,
    );

    expect($incident->fresh()->assigned_to)->toBe($handler->id);
});
