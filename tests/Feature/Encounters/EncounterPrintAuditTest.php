<?php

use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
});

test('successful print creates audit event', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.printed',
        'subject_type' => (new Encounter)->getMorphClass(),
        'subject_id' => $encounter->id,
    ]);
});

test('print audit metadata contains identifiers only', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
        'chief_complaint' => 'Sensitive clinical data',
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();

    $auditLog = AuditLog::query()
        ->where('action', 'encounter.printed')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata)->toHaveKey('encounter_id')
        ->and($auditLog->metadata)->toHaveKey('appointment_id')
        ->and($auditLog->metadata)->toHaveKey('patient_id')
        ->and($auditLog->metadata)->toHaveKey('actor_id')
        ->and($auditLog->metadata)->not->toHaveKey('chief_complaint')
        ->and($auditLog->metadata)->not->toHaveKey('findings')
        ->and($auditLog->metadata)->not->toHaveKey('assessment');
});

test('failed print does not create audit event', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertForbidden();

    $this->assertDatabaseMissing('audit_logs', [
        'action' => 'encounter.printed',
    ]);
});

test('unauthenticated print does not create audit event', function () {
    $encounter = Encounter::factory()->completed()->create();

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('audit_logs', [
        'action' => 'encounter.printed',
    ]);
});

test('print audit records the actor', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $this->actingAs($this->staff);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();

    $auditLog = AuditLog::query()
        ->where('action', 'encounter.printed')
        ->first();

    expect($auditLog->actor_id)->toBe($this->staff->id);
});
