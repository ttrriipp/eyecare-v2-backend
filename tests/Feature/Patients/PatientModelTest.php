<?php

use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('patients have independent system generated clinical identities', function () {
    $patients = Patient::factory()
        ->count(2)
        ->unlinked()
        ->create(['date_of_birth' => '2000-01-02']);

    expect($patients->pluck('patient_number')->unique())->toHaveCount(2)
        ->and($patients->first()->patient_number)->toMatch('/^PAT-[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($patients->first()->date_of_birth)->toBeInstanceOf(CarbonInterface::class)
        ->and($patients->first()->account)->toBeNull();

    $patients->each(fn (Patient $patient) => $this->assertModelExists($patient));
});

test('a patient may link to one patient account', function () {
    $account = User::factory()->patient()->create();
    $patient = Patient::factory()->linkedTo($account)->create();

    expect($patient->account())->toBeInstanceOf(BelongsTo::class)
        ->and($patient->account->is($account))->toBeTrue()
        ->and($account->patient())->toBeInstanceOf(HasOne::class)
        ->and($account->patient->is($patient))->toBeTrue();
});

test('the linked factory state creates a patient role account', function () {
    $patient = Patient::factory()->linked()->create();

    expect($patient->account)->toBeInstanceOf(User::class)
        ->and($patient->account->role->name)->toBe('patient');
});

test('an account cannot link to multiple patient identities', function () {
    $account = User::factory()->patient()->create();

    Patient::factory()->linkedTo($account)->create();

    expect(fn () => Patient::factory()->linkedTo($account)->create())
        ->toThrow(QueryException::class);
});

test('walk in patients do not require an account or email address', function () {
    $patient = Patient::factory()->walkIn()->create();

    expect($patient->user_id)->toBeNull()
        ->and($patient->contact_email)->toBeNull()
        ->and($patient->phone)->not->toBeNull();
});

test('deleting an account preserves and unlinks its patient identity', function () {
    $account = User::factory()->patient()->create();
    $patient = Patient::factory()->linkedTo($account)->create();

    $account->delete();

    expect($patient->fresh()->user_id)->toBeNull();
    $this->assertModelExists($patient);
});

test('patients may be archived without deleting their clinical identity', function () {
    $patient = Patient::factory()->create();

    $patient->delete();

    $this->assertSoftDeleted($patient);
});
