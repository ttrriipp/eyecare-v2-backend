<?php

use App\Enums\AuditEvent;
use App\Filament\Resources\PatientLinkRequests\Pages\ListPatientLinkRequests;
use App\Filament\Resources\PatientLinkRequests\Pages\ViewPatientLinkRequest;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientLinkCandidate;
use App\Models\PatientLinkRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function unlinkedPatientAccount(): User
{
    $user = User::factory()->create();
    $user->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );

    return $user;
}

test('staff sees ranked candidates on the link request page', function () {
    $staff = User::factory()->staff()->create();
    $account = unlinkedPatientAccount();
    $request = PatientLinkRequest::factory()->forAccount($account)->create();
    $strongPatient = Patient::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
    $weakPatient = Patient::factory()->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz']);

    PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $strongPatient->id,
        'match_strength' => 'strong',
        'reason_codes' => ['exact_phone', 'exact_name'],
        'rank' => 1,
    ]);
    PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $weakPatient->id,
        'match_strength' => 'weak',
        'reason_codes' => ['partial_name'],
        'rank' => 2,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewPatientLinkRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Ana Reyes')
        ->assertSee('Ana Cruz')
        ->assertSee('Strong')
        ->assertSee('Weak');
});

test('approving a strong match does not require a decision note', function () {
    $staff = User::factory()->staff()->create();
    $account = unlinkedPatientAccount();
    $request = PatientLinkRequest::factory()->forAccount($account)->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    PatientLinkCandidate::create([
        'link_request_id' => $request->id,
        'patient_id' => $patient->id,
        'match_strength' => 'strong',
        'reason_codes' => ['exact_phone'],
        'rank' => 1,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewPatientLinkRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('approve', ['patient_id' => $patient->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status)->toBe('approved');
});

test('approving a non-strong or unranked match requires a decision note', function () {
    $staff = User::factory()->staff()->create();
    $account = unlinkedPatientAccount();
    $request = PatientLinkRequest::factory()->forAccount($account)->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $this->actingAs($staff);

    Livewire::test(ViewPatientLinkRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('approve', ['patient_id' => $patient->id, 'decision_note' => null])
        ->assertHasActionErrors(['decision_note' => 'required']);

    expect($request->fresh()->status)->toBe('pending');
});

test('expired requests are clearly labelled and have no review actions', function () {
    $staff = User::factory()->staff()->create();
    $account = unlinkedPatientAccount();
    $request = PatientLinkRequest::factory()->forAccount($account)->expired()->create();

    AuditLog::factory()->create([
        'subject_type' => $request->getMorphClass(),
        'subject_id' => $request->id,
        'action' => AuditEvent::PatientLinkRequestExpired->value,
        'metadata' => [
            'account_id' => $account->id,
            'reason' => 'verified_contact_changed',
        ],
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewPatientLinkRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Expired')
        ->assertSee('Verified contact data changed after submission.')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject');
});

test('expired requests have a dedicated staff queue tab', function () {
    $staff = User::factory()->staff()->create();
    $request = PatientLinkRequest::factory()->forAccount(unlinkedPatientAccount())->expired()->create();

    $this->actingAs($staff);

    $component = Livewire::test(ListPatientLinkRequests::class);
    $tabs = $component->instance()->getTabs();
    $expiredQuery = PatientLinkRequest::query();
    $tabs['expired']->modifyQuery($expiredQuery);

    expect($expiredQuery->pluck('id')->all())->toBe([$request->id]);
});
