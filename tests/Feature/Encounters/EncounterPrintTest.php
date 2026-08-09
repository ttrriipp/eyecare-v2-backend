<?php

use App\Enums\EncounterAddendumType;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
    $this->admin = User::factory()->admin()->create();
});

test('authorized panel user can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk()
        ->assertSee($encounter->encounter_number)
        ->assertSee('Blurred vision')
        ->assertSee('Normal anterior segment')
        ->assertSee('Myopia progression')
        ->assertSee('Update prescription');
});

test('staff can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($this->staff);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();
});

test('admin can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($this->admin);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();
});

test('unauthenticated user cannot print encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertRedirect();
});

test('in-progress encounter cannot be printed', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertForbidden();
});

test('planned encounter cannot be printed', function () {
    $encounter = Encounter::factory()->create([
        'status' => EncounterStatus::Planned,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertForbidden();
});

test('print view shows addenda', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 1,
        'type' => EncounterAddendumType::Correction,
        'reason' => 'Transcription error',
        'content' => 'Corrected findings section.',
        'authored_by' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk()
        ->assertSee('Original Clinical Record')
        ->assertSee('Addenda')
        ->assertSee('Correction')
        ->assertSee('Transcription error')
        ->assertSee('Corrected findings section.');
});

test('print view shows patient information', function () {
    $patient = Patient::factory()->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
    ]);
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk()
        ->assertSee('Maria Santos');
});

test('print view shows optometrist name', function () {
    $this->optometrist->update([
        'first_name' => 'Juan',
        'middle_name' => null,
        'last_name' => 'Dela Cruz',
    ]);
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $this->actingAs($this->optometrist);

    $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk()
        ->assertSee('Juan Dela Cruz');
});

test('print escapes user-supplied content', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
        'chief_complaint' => '<script>alert("xss")</script>',
    ]);

    $this->actingAs($this->optometrist);

    $response = $this->get(route('encounters.print', ['encounter' => $encounter->id]))
        ->assertOk();

    expect($response->getContent())->not->toContain('<script>alert("xss")</script>')
        ->and($response->getContent())->toContain('&lt;script&gt;');
});
